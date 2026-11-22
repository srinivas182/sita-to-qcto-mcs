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
use Modules\Tenancy\Database\Factories\TenantDomainFactory;
use Modules\Tenancy\Enums\DnsStatus;
use Modules\Tenancy\Enums\DomainType;
use Modules\Tenancy\Enums\SslStatus;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $hostname
 * @property DomainType $type
 * @property string $verification_token
 * @property DnsStatus $dns_status
 * @property SslStatus $ssl_status
 * @property string|null $provider_ref
 * @property array<string, mixed>|null $last_check
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $verified_at
 * @property bool $is_primary
 */
class TenantDomain extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'tenant_id', 'hostname', 'type', 'verification_token', 'dns_status', 'ssl_status',
        'provider_ref', 'last_check', 'last_checked_at', 'verified_at', 'is_primary',
    ];

    /**
     * The token is a secret until it is published in DNS; keep it out of logs.
     *
     * @var list<string>
     */
    protected array $auditRedact = ['verification_token'];

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'dns_status' => DnsStatus::class,
            'ssl_status' => SslStatus::class,
            'last_check' => 'array',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Whether requests to this hostname may be routed to the tenant. */
    public function isRoutable(): bool
    {
        return $this->dns_status === DnsStatus::Verified;
    }

    protected static function newFactory(): TenantDomainFactory
    {
        return TenantDomainFactory::new();
    }
}
