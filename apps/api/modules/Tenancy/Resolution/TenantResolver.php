<?php

declare(strict_types=1);

namespace Modules\Tenancy\Resolution;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDomain;

/**
 * Finds the tenant for a hostname or a tenant code.
 *
 * Lookups are cached (Redis in production) as the tenant's raw attributes so a warm
 * request resolves without touching the database. Misses are cached briefly to
 * protect the database from random-host floods. Entries are invalidated by
 * TenantCache when tenants or domains change.
 */
final class TenantResolver
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly TenantContext $context,
    ) {}

    public static function hostKey(string $host): string
    {
        return 'tenancy:host:'.$host;
    }

    public static function codeKey(string $code): string
    {
        return 'tenancy:code:'.strtolower($code);
    }

    public function byHost(string $host): ?Tenant
    {
        $host = Hostname::normalise($host);

        if (! Hostname::isValid($host)) {
            return null;
        }

        return $this->remember(self::hostKey($host), function () use ($host): ?Tenant {
            $domain = TenantDomain::query()->where('hostname', $host)->with('tenant')->first();

            return $domain !== null && $domain->isRoutable() ? $domain->tenant : null;
        });
    }

    public function byCode(string $code): ?Tenant
    {
        $code = strtolower(trim($code));

        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/', $code)) {
            return null;
        }

        return $this->remember(self::codeKey($code), fn (): ?Tenant => Tenant::query()->where('code', $code)->first());
    }

    /** @param  Closure(): ?Tenant  $load */
    private function remember(string $key, Closure $load): ?Tenant
    {
        $store = $this->store();
        $cached = $store->get($key);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return (new Tenant)->newFromBuilder($cached);
        }

        if ($cached === false) {
            return null;
        }

        $tenant = $this->context->runAsPlatform($load);

        if ($tenant === null) {
            $store->put($key, false, (int) config('platform.tenancy.negative_cache_ttl', 60));

            return null;
        }

        $store->put($key, $tenant->getAttributes(), (int) config('platform.tenancy.cache_ttl', 600));

        return $tenant;
    }

    public function store(): Repository
    {
        $name = config('platform.tenancy.cache_store');

        return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
    }
}
