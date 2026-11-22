<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A saved card (a gateway token, never the card number).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $driver
 * @property string $token encrypted at rest
 * @property string|null $customer_ref
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property string|null $fingerprint
 */
final class PaymentMethod extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['tenant_id', 'driver', 'token', 'customer_ref', 'brand', 'last4', 'exp_month', 'exp_year', 'fingerprint'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'exp_month' => 'integer', 'exp_year' => 'integer'];
    }

    public function isExpired(): bool
    {
        if ($this->exp_year === null || $this->exp_month === null) {
            return false;
        }

        return now()->startOfMonth()->gt(now()->setDate($this->exp_year, $this->exp_month, 1));
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'driver' => $this->driver,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'expired' => $this->isExpired(),
        ];
    }
}
