<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * The provider as invoice recipient.
 *
 * @property string $tenant_id
 * @property string $legal_name
 * @property string|null $vat_number
 * @property string|null $registration_number
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string $email
 * @property string|null $purchase_order
 */
final class BillingProfile extends Model
{
    use Auditable, BelongsToTenant;

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id', 'legal_name', 'vat_number', 'registration_number', 'address_line1', 'address_line2',
        'city', 'province', 'postal_code', 'email', 'purchase_order',
    ];

    /** @return array<string, string|null> */
    public function snapshot(): array
    {
        return $this->only($this->fillable);
    }
}
