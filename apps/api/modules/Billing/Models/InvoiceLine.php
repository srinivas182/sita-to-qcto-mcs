<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $kind
 * @property string $description
 * @property int $quantity
 * @property int $unit_cents
 * @property int $amount_cents
 */
final class InvoiceLine extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'invoice_id', 'kind', 'description', 'quantity', 'unit_cents', 'amount_cents'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cents' => 'integer', 'amount_cents' => 'integer'];
    }
}
