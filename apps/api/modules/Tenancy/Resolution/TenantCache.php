<?php

declare(strict_types=1);

namespace Modules\Tenancy\Resolution;

use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDomain;

/** Drops cached resolution entries when tenants or their domains change. */
final class TenantCache
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function forgetDomain(TenantDomain $domain): void
    {
        $store = $this->resolver->store();
        $store->forget(TenantResolver::hostKey($domain->hostname));

        $original = $domain->getOriginal('hostname');

        if (is_string($original) && $original !== $domain->hostname) {
            $store->forget(TenantResolver::hostKey($original));
        }
    }

    public function forgetTenant(Tenant $tenant): void
    {
        $store = $this->resolver->store();
        $store->forget(TenantResolver::codeKey($tenant->code));

        $original = $tenant->getOriginal('code');

        if (is_string($original) && $original !== $tenant->code) {
            $store->forget(TenantResolver::codeKey($original));
        }

        $hosts = $this->context->runAsPlatform(
            fn () => TenantDomain::query()->where('tenant_id', $tenant->id)->pluck('hostname')->all(),
        );

        foreach ($hosts as $host) {
            $store->forget(TenantResolver::hostKey((string) $host));
        }
    }
}
