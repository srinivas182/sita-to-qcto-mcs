<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $reference
 * @property string $driver
 * @property string|null $gateway_reference
 * @property string $method
 * @property int $amount_cents
 * @property string $status pending | succeeded | failed | cancelled
 * @property bool $save_method
 * @property string|null $failure_message
 * @property string|null $recorded_by
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property-read Invoice $invoice
 */
final class Payment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'reference', 'driver', 'gateway_reference', 'method', 'amount_cents',
        'status', 'save_method', 'failure_message', 'recorded_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'save_method' => 'boolean', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'invoice_id' => $this->invoice_id,
            'driver' => $this->driver,
            'method' => $this->method,
            'amount_cents' => $this->amount_cents,
            'status' => $this->status,
            'failure_message' => $this->failure_message,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
