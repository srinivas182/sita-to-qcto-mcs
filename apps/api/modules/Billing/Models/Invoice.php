<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A tax invoice. Immutable once issued: corrections are credit notes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $subscription_id
 * @property string|null $number
 * @property string $status open | paid | void
 * @property string $currency
 * @property int $subtotal_cents
 * @property string $vat_rate
 * @property int $vat_cents
 * @property int $total_cents
 * @property int $amount_paid_cents
 * @property int $amount_credited_cents
 * @property array<string, mixed> $supplier
 * @property array<string, mixed> $recipient
 * @property string $eft_reference
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $paid_at
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property string|null $pdf_file_id
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, Payment> $payments
 */
final class Invoice extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'subscription_id', 'number', 'status', 'currency', 'subtotal_cents', 'vat_rate', 'vat_cents',
        'total_cents', 'amount_paid_cents', 'amount_credited_cents', 'supplier', 'recipient', 'eft_reference',
        'period_start', 'period_end', 'issued_at', 'due_at', 'paid_at', 'attempts', 'next_attempt_at', 'pdf_file_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'vat_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'amount_credited_cents' => 'integer',
            'supplier' => 'array',
            'recipient' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function amountDue(): int
    {
        return max(0, $this->total_cents - $this->amount_paid_cents - $this->amount_credited_cents);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at->isPast();
    }

    /** @return array<string, mixed> */
    public function present(bool $withLines = false): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status === 'open' && $this->isOverdue() ? 'overdue' : $this->status,
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'vat_rate' => (float) $this->vat_rate,
            'vat_cents' => $this->vat_cents,
            'total_cents' => $this->total_cents,
            'amount_paid_cents' => $this->amount_paid_cents,
            'amount_credited_cents' => $this->amount_credited_cents,
            'amount_due_cents' => $this->amountDue(),
            'eft_reference' => $this->eft_reference,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'due_at' => $this->due_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'recipient' => ['legal_name' => $this->recipient['legal_name'] ?? null],
            ...($withLines ? ['lines' => $this->lines->map(fn (InvoiceLine $l): array => [
                'kind' => $l->kind, 'description' => $l->description, 'quantity' => $l->quantity,
                'unit_cents' => $l->unit_cents, 'amount_cents' => $l->amount_cents,
            ])->all()] : []),
        ];
    }
}
