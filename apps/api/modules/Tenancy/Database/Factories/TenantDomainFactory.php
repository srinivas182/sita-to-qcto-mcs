<?php

declare(strict_types=1);

namespace Modules\Tenancy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Tenancy\Enums\DnsStatus;
use Modules\Tenancy\Enums\DomainType;
use Modules\Tenancy\Enums\SslStatus;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDomain;

/** @extends Factory<TenantDomain> */
final class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'hostname' => 'learn.'.$this->faker->unique()->domainWord().'.co.za',
            'type' => DomainType::Custom,
            'verification_token' => 'skills-verify='.Str::lower(Str::random(32)),
            'dns_status' => DnsStatus::Verified,
            'ssl_status' => SslStatus::Active,
            'verified_at' => now(),
            'is_primary' => false,
        ];
    }
}
