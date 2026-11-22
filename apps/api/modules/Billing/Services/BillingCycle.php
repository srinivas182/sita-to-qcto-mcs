<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\Notification;
use Modules\Billing\Models\BillingProfile;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\AccountRestricted;
use Modules\Billing\Notifications\TrialEnding;
use Modules\Billing\Support\SubscriptionStatus;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * The billing clock (`billing:run`, every 15 minutes). For each provider:
 *  1. trial reminders, and the first invoice when a trial ends;
 *  2. renewal at period end (pending downgrades and cancellations take effect);
 *  3. automatic card charges and retries on the configured days;
 *  4. status from overdue invoices: past due → read-only → suspended.
 * Each provider runs in its own context; one failing never stops the others.
 */
final class BillingCycle
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentService $payments,
        private readonly BillingRules $rules,
    ) {}

    /** @return array{providers: int, invoices: int, charges: int, errors: int} */
    public function run(): array
    {
        $stats = ['providers' => 0, 'invoices' => 0, 'charges' => 0, 'errors' => 0];
        $tenantIds = $this->context->runAsPlatform(fn () => Subscription::query()->pluck('tenant_id')->all());

        foreach ($tenantIds as $tenantId) {
            $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->first());
            if ($tenant === null) {
                continue;
            }

            try {
                $this->context->runAs($tenant, function () use ($tenant, &$stats): void {
                    $stats['providers']++;
                    $this->forProvider($tenant, $stats);
                });
            } catch (Throwable $e) {
                $stats['errors']++;
                report($e);
            }
        }

        return $stats;
    }

    /** @param  array{providers: int, invoices: int, charges: int, errors: int}  $stats */
    private function forProvider(Tenant $tenant, array &$stats): void
    {
        $subscription = Subscription::query()->with(['package', 'paymentMethod'])->first();
        if ($subscription === null) {
            return;
        }

        $rules = $this->rules->get();
        $now = now();

        // 1. Trials.
        $trialEnds = $subscription->trial_ends_at;
        if ($subscription->status === SubscriptionStatus::Trialing && $trialEnds !== null) {
            if ($subscription->trial_reminder_sent_at === null && $trialEnds->copy()->subDays($rules['trial_reminder_days'])->lte($now)) {
                $this->mail($tenant, new TrialEnding($tenant, $subscription));
                $subscription->forceFill(['trial_reminder_sent_at' => $now])->save();
            }

            if ($trialEnds->lte($now)) {
                $subscription->status = SubscriptionStatus::Active;
                $subscription->save();
                $this->charge($tenant, $this->subscriptions->startPeriod($tenant, $subscription, $trialEnds), $subscription, $stats);
                $stats['invoices']++;
            }
        }

        // 2. Renewal (not while suspended: no new invoices pile up).
        $renewing = in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::ReadOnly], true);
        $periodEnd = $subscription->current_period_end;
        if ($renewing && $periodEnd !== null && $periodEnd->lte($now)) {
            if ($subscription->cancel_at_period_end) {
                $subscription->fill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $now])->save();
            } else {
                if ($subscription->pending_package_id !== null) {
                    $subscription->fill(['package_id' => $subscription->pending_package_id, 'pending_package_id' => null])->save();
                    $subscription->load('package');
                    $tenant->forceFill(['package_code' => $subscription->package->code])->save();
                }
                $this->charge($tenant, $this->subscriptions->startPeriod($tenant, $subscription, $periodEnd), $subscription, $stats);
                $stats['invoices']++;
            }
        }

        // 3. Retries.
        if ($subscription->collection === 'auto' && $subscription->paymentMethod !== null) {
            Invoice::query()->where('status', 'open')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', $now)->get()
                ->each(fn (Invoice $invoice) => $this->charge($tenant, $invoice, $subscription, $stats));
        }

        // 4. Status.
        $before = $subscription->status;
        $after = $this->subscriptions->refreshStatus($subscription)->status;
        if ($before !== $after && in_array($after, [SubscriptionStatus::ReadOnly, SubscriptionStatus::Suspended], true)) {
            $this->mail($tenant, new AccountRestricted($tenant, $after));
        }
    }

    /**
     * Charge the saved card if the plan pays automatically, and schedule the next
     * retry after a failure.
     *
     * @param  array{providers: int, invoices: int, charges: int, errors: int}  $stats
     */
    private function charge(Tenant $tenant, Invoice $invoice, Subscription $subscription, array &$stats): void
    {
        $method = $subscription->paymentMethod;
        if ($subscription->collection !== 'auto' || $method === null || $invoice->status !== 'open' || $method->isExpired()) {
            return;
        }

        $retryDays = $this->rules->get()['retry_days'];
        $payment = $this->payments->chargeSaved($tenant, $invoice, $method);
        $stats['charges']++;

        $invoice->refresh();
        $attempts = $invoice->attempts + 1;
        $next = null;

        if ($payment->status !== 'succeeded' && $invoice->status === 'open') {
            // Retries are counted from the due date: e.g. days 1, 3 and 7 after it.
            $day = $retryDays[$attempts - 1] ?? null;
            $next = $day !== null ? $invoice->due_at->copy()->addDays($day) : null;
            if ($next !== null && $next->isPast()) {
                $next = now()->addHour();
            }
        }

        $invoice->forceFill(['attempts' => $attempts, 'next_attempt_at' => $next])->save();
    }

    private function mail(Tenant $tenant, \Illuminate\Notifications\Notification $notification): void
    {
        $email = BillingProfile::query()->find($tenant->id)?->email;
        if ($email !== null) {
            Notification::route('mail', $email)->notify($notification);
        }
    }
}
