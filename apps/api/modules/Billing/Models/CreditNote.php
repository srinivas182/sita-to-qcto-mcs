<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $number
 * @property string $reason
 * @property int $subtotal_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property array<string, mixed> $supplier
 * @property array<string, mixed> $recipient
 * @property string|null $issued_by
 * @property Carbon $issued_at
 * @property string|null $pdf_file_id
 */
final class CreditNote extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'number', 'reason', 'subtotal_cents', 'vat_cents', 'total_cents',
        'supplier', 'recipient', 'issued_by', 'issued_at', 'pdf_file_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer', 'vat_cents' => 'integer', 'total_cents' => 'integer',
            'supplier' => 'array', 'recipient' => 'array', 'issued_at' => 'datetime',
        ];
    }
}
