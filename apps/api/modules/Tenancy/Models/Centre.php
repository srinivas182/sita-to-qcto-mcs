<?php

declare(strict_types=1);

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Concerns\BelongsToTenant;
use Modules\Tenancy\Database\Factories\CentreFactory;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $accreditation_number
 * @property Carbon|null $accreditation_expires_on
 * @property string $status
 * @property string $accreditation_type sdp | ac
 * @property list<string>|null $scope qualification (SAQA) IDs
 * @property string|null $address_line1
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $alerted_for last expiry alert sent (90, 60, 30, 0 days)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Centre extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CentreFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'tenant_id', 'name', 'accreditation_number', 'accreditation_expires_on', 'status', 'accreditation_type', 'scope',
        'address_line1', 'city', 'province', 'postal_code', 'latitude', 'longitude', 'alerted_for',
    ];

    protected function casts(): array
    {
        return ['accreditation_expires_on' => 'date', 'scope' => 'array', 'alerted_for' => 'integer'];
    }

    /** Days until accreditation ends (negative once expired), or null when unknown. */
    public function daysToExpiry(): ?int
    {
        return $this->accreditation_expires_on === null ? null : (int) now()->startOfDay()->diffInDays($this->accreditation_expires_on, false);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        $days = $this->daysToExpiry();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'accreditation_type' => $this->accreditation_type,
            'accreditation_number' => $this->accreditation_number,
            'accreditation_expires_on' => $this->accreditation_expires_on?->toDateString(),
            'days_to_expiry' => $days,
            'expiry_state' => match (true) {
                $days === null => 'unknown',
                $days < 0 => 'expired',
                $days <= 30 => 'critical',
                $days <= 90 => 'soon',
                default => 'ok',
            },
            'scope' => $this->scope ?? [],
            'address_line1' => $this->address_line1,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): CentreFactory
    {
        return CentreFactory::new();
    }
}
