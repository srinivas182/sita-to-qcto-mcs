<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Audit\AuditLogger;
use Modules\Billing\Exceptions\BillingError;
use Modules\Billing\Models\BillingProfile;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Package;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\SubscriptionStatus;
use Modules\Tenancy\Models\Tenant;

/**
 * A provider's plan: start (with a trial), change, cancel at period end, resume,
 * and the status that follows from its invoices. Runs in the provider's context.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly BillingRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): ?Subscription
    {
        return Subscription::query()->with(['package', 'pendingPackage', 'paymentMethod'])->first();
    }

    /**
     * Start a subscription with the package's trial (or none when trial_days is 0).
     *
     * @param  array<string, mixed>  $profile  recipient details for tax invoices
     */
    public function start(Tenant $tenant, Package $package, string $interval, array $profile): Subscription
    {
        if (! $package->active) {
            throw new BillingError('This package is no longer offered.', 'package_inactive');
        }

        if (Subscription::query()->exists()) {
            throw new BillingError('This provider already has a plan. Change it instead.', 'already_subscribed', 409);
        }

        $this->saveProfile($tenant, $profile);
        $trialDays = $package->trial_days;

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'interval' => $interval,
            'status' => $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'collection' => 'invoice',
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
        ]);

        $tenant->forceFill(['package_code' => $package->code])->save();

        if ($trialDays === 0) {
            $this->startPeriod($tenant, $subscription, now());
        }

        return $subscription->load('package');
    }

    /**
     * Upgrade now (the difference for the rest of the period is invoiced) or
     * downgrade at the end of the period.
     */
    public function change(Tenant $tenant, Subscription $subscription, Package $package, string $interval): Subscription
    {
        if (! $package->active) {
            throw new BillingError('This package is no longer offered.', 'package_inactive');
        }

        $current = $subscription->package;
        $now = now();
        $sameInterval = $interval === $subscription->interval;
        $upgrade = $package->baseFeeFor($interval) > $current->baseFeeFor($subscription->interval) || ! $sameInterval;

        if ($subscription->status === SubscriptionStatus::Trialing || $subscription->current_period_end === null) {
            // During the trial a change simply takes effect.
            $subscription->fill(['package_id' => $package->id, 'interval' => $interval, 'pending_package_id' => null])->save();
        } elseif ($upgrade) {
            DB::transaction(function () use ($tenant, $subscription, $package, $current, $interval, $now, $sameInterval): void {
                if ($sameInterval) {
                    $this->prorate($tenant, $subscription, $current, $package, $now);
                    $subscription->fill(['package_id' => $package->id, 'pending_package_id' => null])->save();
                } else {
                    // New interval: credit the unused part and start a fresh period now.
                    $this->creditUnused($subscription, $now);
                    $subscription->fill(['package_id' => $package->id, 'interval' => $interval, 'pending_package_id' => null])->save();
                    $this->startPeriod($tenant, $subscription, $now);
                }
            });
        } else {
            $subscription->fill(['pending_package_id' => $package->id])->save();
        }

        $tenant->forceFill(['package_code' => $package->code])->save();
        $this->audit->record('subscription.changed', $subscription, meta: ['from' => $current->code, 'to' => $package->code, 'interval' => $interval]);

        return $subscription->refresh()->load(['package', 'pendingPackage']);
    }

    public function cancelAtPeriodEnd(Subscription $subscription): Subscription
    {
        $subscription->fill(['cancel_at_period_end' => true])->save();

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            throw new BillingError('This plan has ended. Choose a package to start again.', 'cancelled');
        }

        $subscription->fill(['cancel_at_period_end' => false])->save();

        return $subscription;
    }

    /** Begin a billing period and invoice it. */
    public function startPeriod(Tenant $tenant, Subscription $subscription, Carbon $start): Invoice
    {
        $package = $subscription->package()->firstOrFail();
        $end = $subscription->interval === 'year' ? $start->copy()->addYear() : $start->copy()->addMonthNoOverflow();

        $subscription->fill(['current_period_start' => $start, 'current_period_end' => $end])->save();

        return $this->invoices->issue($tenant, $this->invoices->periodLines($tenant, $subscription, $package, $start, $end), $subscription, $start, $end);
    }

    /**
     * Status from the oldest unpaid invoice: past due once it is overdue, read-only
     * and then suspended after the configured days. Paid up → active.
     */
    public function refreshStatus(Subscription $subscription): Subscription
    {
        if (in_array($subscription->status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Trialing], true)) {
            return $subscription;
        }

        $oldest = Invoice::query()->where('status', 'open')->where('due_at', '<', now())->orderBy('due_at')->first();
        $rules = $this->rules->get();
        $before = $subscription->status;

        if ($oldest === null) {
            $subscription->fill(['status' => SubscriptionStatus::Active, 'past_due_since' => null]);
        } else {
            $days = (int) $oldest->due_at->diffInDays(now());
            $subscription->past_due_since = $oldest->due_at;
            $subscription->status = match (true) {
                $days >= $rules['suspend_after_days'] => SubscriptionStatus::Suspended,
                $days >= $rules['read_only_after_days'] => SubscriptionStatus::ReadOnly,
                default => SubscriptionStatus::PastDue,
            };
        }

        if ($subscription->isDirty()) {
            $subscription->save();
        }

        if ($before !== $subscription->status) {
            $this->audit->record('subscription.status_changed', $subscription, meta: ['from' => $before->value, 'to' => $subscription->status->value]);
        }

        return $subscription;
    }

    /** @param  array<string, mixed>  $profile */
    public function saveProfile(Tenant $tenant, array $profile): BillingProfile
    {
        return BillingProfile::query()->updateOrCreate(['tenant_id' => $tenant->id], $profile);
    }

    /** Invoice the price difference for the days left in the period. */
    private function prorate(Tenant $tenant, Subscription $subscription, Package $from, Package $to, Carbon $now): void
    {
        $start = $subscription->current_period_start ?? $now;
        $end = $subscription->current_period_end ?? $now;
        $total = max(1, $start->diffInSeconds($end));
        $left = max(0, $now->diffInSeconds($end));
        $diff = $to->baseFeeFor($subscription->interval) - $from->baseFeeFor($subscription->interval);
        $amount = (int) round($diff * $left / $total);

        if ($amount > 0) {
            $this->invoices->issue($tenant, [[
                'kind' => 'proration',
                'description' => sprintf('Upgrade from %s to %s for the rest of the period (to %s)', $from->name, $to->name, $end->copy()->subDay()->format('j M Y')),
                'quantity' => 1,
                'unit_cents' => $amount,
                'amount_cents' => $amount,
            ]], $subscription, $now, $end);
        }
    }

    /** Credit the unused share of the current period's paid base fee. */
    private function creditUnused(Subscription $subscription, Carbon $now): void
    {
        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->where('status', 'paid')
            ->whereNotNull('period_start')->latest('issued_at')->first();

        if ($invoice === null || $subscription->current_period_end === null || $subscription->current_period_start === null) {
            return;
        }

        $base = (int) $invoice->lines()->where('kind', 'base')->sum('amount_cents');
        $share = $now->diffInSeconds($subscription->current_period_end) / max(1, $subscription->current_period_start->diffInSeconds($subscription->current_period_end));
        $amount = (int) floor($base * max(0, min(1, $share)));

        if ($amount > 0) {
            $this->invoices->credit($invoice, $amount, 'Unused part of the previous plan after changing billing interval', null);
        }
    }
}
