<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Audit\AuditLogger;
use Modules\Billing\Exceptions\BillingError;
use Modules\Billing\Jobs\RenderBillingDocument;
use Modules\Billing\Models\BillingProfile;
use Modules\Billing\Models\CreditNote;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceLine;
use Modules\Billing\Models\Package;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\InvoiceIssued;
use Modules\Billing\Support\Money;
use Modules\Settings\Services\CompanySettings;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Builds and issues SARS tax invoices and credit notes.
 *
 * Every invoice carries, as snapshots taken at issue: the words "Tax Invoice", the
 * supplier's name, address and VAT number (Settings → Company), the recipient's name,
 * address and VAT number (the provider's billing profile), a sequential number, the
 * date, the description of each line, and the VAT in rands. Snapshots keep the
 * document unchanged even if details change later.
 */
final class InvoiceService
{
    public function __construct(
        private readonly CompanySettings $company,
        private readonly DocumentNumbers $numbers,
        private readonly LearnerMeter $meter,
        private readonly BillingRules $rules,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Lines for a subscription's period: the base fee for the coming period and
     * active learners above the package's included number.
     *
     * @return list<array{kind: string, description: string, quantity: int, unit_cents: int, amount_cents: int}>
     */
    public function periodLines(Tenant $tenant, Subscription $subscription, Package $package, Carbon $start, Carbon $end): array
    {
        $interval = $subscription->interval;
        $label = $interval === 'year' ? 'annual' : 'monthly';
        $lines = [[
            'kind' => 'base',
            'description' => sprintf('%s package, %s fee, %s to %s', $package->name, $label, $start->format('j M Y'), $end->copy()->subDay()->format('j M Y')),
            'quantity' => 1,
            'unit_cents' => $package->baseFeeFor($interval),
            'amount_cents' => $package->baseFeeFor($interval),
        ]];

        $learners = $this->meter->activeLearners($tenant);
        $extra = max(0, $learners - $package->included_learners);
        // Learners are billed per month; a yearly plan bills 12 months of them.
        $months = $interval === 'year' ? 12 : 1;

        if ($extra > 0 && $package->per_learner_cents > 0) {
            $unit = $package->per_learner_cents * $months;
            $lines[] = [
                'kind' => 'learners',
                'description' => sprintf('Active learners above %d included (%d active)%s', $package->included_learners, $learners, $months > 1 ? ', 12 months' : ''),
                'quantity' => $extra,
                'unit_cents' => $unit,
                'amount_cents' => $unit * $extra,
            ];
        }

        return $lines;
    }

    /**
     * What the next invoice would be, without issuing it.
     *
     * @return array<string, mixed>
     */
    public function estimate(Tenant $tenant, Subscription $subscription): array
    {
        $package = $subscription->pendingPackage ?? $subscription->package;
        $start = ($subscription->current_period_end ?? $subscription->trial_ends_at ?? now())->copy();
        $end = $subscription->interval === 'year' ? $start->copy()->addYear() : $start->copy()->addMonthNoOverflow();
        $lines = $this->periodLines($tenant, $subscription, $package, $start, $end);

        return [...$this->totals($lines), 'date' => $start->toIso8601String(), 'lines' => $lines, 'active_learners' => $this->meter->activeLearners($tenant)];
    }

    /**
     * Issue an invoice for a provider. Runs in the provider's context.
     *
     * @param  list<array{kind: string, description: string, quantity: int, unit_cents: int, amount_cents: int}>  $lines
     */
    public function issue(Tenant $tenant, array $lines, ?Subscription $subscription = null, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): Invoice
    {
        if ($lines === []) {
            throw new BillingError('An invoice needs at least one line.', 'empty_invoice');
        }

        $company = $this->company->get();
        $totals = $this->totals($lines);

        $invoice = $this->context->runAs($tenant, fn (): Invoice => DB::transaction(function () use ($tenant, $lines, $subscription, $periodStart, $periodEnd, $company, $totals): Invoice {
            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription?->id,
                'number' => $this->numbers->next((string) $company['invoice_prefix']),
                'status' => $totals['total_cents'] === 0 ? 'paid' : 'open',
                'currency' => 'ZAR',
                ...$totals,
                'supplier' => $this->supplier($company),
                'recipient' => $this->recipient($tenant),
                'eft_reference' => $this->eftReference($tenant),
                'period_start' => $periodStart,
                'period_end' => $periodEnd?->copy()->subDay(),
                'issued_at' => now(),
                'due_at' => now()->addDays($this->rules->get()['invoice_due_days']),
                'paid_at' => $totals['total_cents'] === 0 ? now() : null,
            ]);

            foreach ($lines as $line) {
                InvoiceLine::query()->create(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, ...$line]);
            }

            return $invoice;
        }));

        $this->context->runAs($tenant, fn () => $this->audit->record('invoice.issued', $invoice, meta: ['number' => $invoice->number, 'total_cents' => $invoice->total_cents]));
        RenderBillingDocument::dispatch($tenant->id, 'invoice', $invoice->id);

        if ($invoice->status === 'open') {
            $email = $this->context->runAs($tenant, fn () => BillingProfile::query()->find($tenant->id)?->email);
            if ($email !== null) {
                Notification::route('mail', $email)->notify(new InvoiceIssued($tenant, $invoice));
            }
        }

        return $invoice;
    }

    /** Credit part or all of an invoice (corrections never edit the invoice). */
    public function credit(Invoice $invoice, int $subtotalCents, string $reason, ?string $actorId): CreditNote
    {
        $vat = Money::vat($subtotalCents, (float) $invoice->vat_rate);
        $creditable = $invoice->total_cents - $invoice->amount_credited_cents;

        if ($subtotalCents <= 0 || $subtotalCents + $vat > $creditable) {
            throw new BillingError('The credit must be more than zero and no more than the invoice still holds.', 'invalid_credit');
        }

        $note = DB::transaction(function () use ($invoice, $subtotalCents, $vat, $reason, $actorId): CreditNote {
            $note = CreditNote::query()->create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'number' => $this->numbers->next('CN'),
                'reason' => $reason,
                'subtotal_cents' => $subtotalCents,
                'vat_cents' => $vat,
                'total_cents' => $subtotalCents + $vat,
                'supplier' => $invoice->supplier,
                'recipient' => $invoice->recipient,
                'issued_by' => $actorId,
                'issued_at' => now(),
            ]);

            $invoice->amount_credited_cents += $note->total_cents;
            if ($invoice->status === 'open' && $invoice->amountDue() === 0) {
                $invoice->status = 'paid';
                $invoice->paid_at = now();
            }
            $invoice->save();

            return $note;
        });

        $this->audit->record('credit_note.issued', $note, meta: ['invoice' => $invoice->number, 'total_cents' => $note->total_cents, 'reason' => $reason]);
        RenderBillingDocument::dispatch($invoice->tenant_id, 'credit_note', $note->id);

        return $note;
    }

    /**
     * @param  list<array{amount_cents: int}>  $lines
     * @return array{subtotal_cents: int, vat_rate: float, vat_cents: int, total_cents: int}
     */
    public function totals(array $lines): array
    {
        $rate = (float) $this->company->get()['vat_rate'];
        $subtotal = array_sum(array_column($lines, 'amount_cents'));
        $vat = Money::vat($subtotal, $rate);

        return ['subtotal_cents' => $subtotal, 'vat_rate' => $rate, 'vat_cents' => $vat, 'total_cents' => $subtotal + $vat];
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    private function supplier(array $company): array
    {
        if (! $this->company->isComplete()) {
            throw new BillingError('Fill in Settings → Company before invoices can be issued.', 'company_incomplete');
        }

        return array_intersect_key($company, array_flip([
            'registered_name', 'trading_name', 'registration_number', 'vat_number', 'address_line1', 'address_line2',
            'city', 'province', 'postal_code', 'country', 'email', 'phone', 'website', 'bank_details', 'invoice_footer',
        ]));
    }

    /** @return array<string, mixed> */
    private function recipient(Tenant $tenant): array
    {
        $profile = BillingProfile::query()->find($tenant->id);

        return $profile?->snapshot() ?? ['legal_name' => $tenant->legal_name, 'registration_number' => $tenant->registration_number];
    }

    /** Short, unique and easy to type on a banking app: TYM-7K3Q9D. */
    private function eftReference(Tenant $tenant): string
    {
        return strtoupper(Str::limit($tenant->code, 10, '')).'-'.strtoupper(Str::random(6));
    }
}
