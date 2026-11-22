<?php

declare(strict_types=1);

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Database\Factories\TenantFactory;
use Modules\Tenancy\Enums\TenantStatus;

/**
 * A training provider (TP): the unit of isolation on the platform.
 *
 * @property string $id
 * @property string $cell_id
 * @property string $code
 * @property string $legal_name
 * @property string $trading_name
 * @property string|null $registration_number
 * @property TenantStatus $status
 * @property string|null $package_code
 * @property array<string, mixed>|null $branding
 * @property string|null $created_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 */
class Tenant extends Model
{
    use Auditable;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'cell_id', 'code', 'legal_name', 'trading_name', 'registration_number',
        'status', 'package_code', 'branding', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'branding' => 'array',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cell, $this> */
    public function cell(): BelongsTo
    {
        return $this->belongsTo(Cell::class);
    }

    /** @return HasMany<TenantDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    /** @return HasMany<Centre, $this> */
    public function centres(): HasMany
    {
        return $this->hasMany(Centre::class);
    }

    public function auditTenantId(): string
    {
        return $this->id;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
