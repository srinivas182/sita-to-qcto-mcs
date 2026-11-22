<?php

declare(strict_types=1);

namespace Modules\Tenancy\Context;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Tenancy\Models\Tenant;

/**
 * The tenant the current request or job acts for.
 *
 * There are three states:
 *  - tenant:   one training provider; the database shows only its rows
 *  - platform: explicit cross-tenant work (Super Admin, onboarding, schedulers)
 *  - none:     the default; the database shows no tenant rows at all
 *
 * Every change is pushed to PostgreSQL as session settings (`app.tenant_id`,
 * `app.context`) that the row-level security policies read, so isolation holds even
 * for raw queries that bypass Eloquent scopes.
 *
 * Note: a setting changed inside a transaction that later rolls back reverts in the
 * database. Prefer runAs()/runAsPlatform(), which re-apply the outer state afterwards.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $platform = false;

    public function __construct(private readonly DatabaseManager $db) {}

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): ?string
    {
        return $this->tenant?->id;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    public function isEmpty(): bool
    {
        return $this->tenant === null && ! $this->platform;
    }

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->platform = false;
        $this->apply();
    }

    public function setPlatform(): void
    {
        $this->tenant = null;
        $this->platform = true;
        $this->apply();
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->platform = false;
        $this->apply();
    }

    /**
     * Run a callback as the given tenant and restore the previous context afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        [$previousTenant, $previousPlatform] = [$this->tenant, $this->platform];
        $this->setTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->restore($previousTenant, $previousPlatform);
        }
    }

    /**
     * Run a callback in the cross-tenant platform context.
     *
     * Only trusted platform code may call this (Super Admin services after their own
     * authorisation checks, onboarding, schedulers). Never call it from request input.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsPlatform(Closure $callback): mixed
    {
        [$previousTenant, $previousPlatform] = [$this->tenant, $this->platform];
        $this->setPlatform();

        try {
            return $callback();
        } finally {
            $this->restore($previousTenant, $previousPlatform);
        }
    }

    /** @return array{tenant_id: string|null, platform: bool} */
    public function snapshot(): array
    {
        return ['tenant_id' => $this->tenantId(), 'platform' => $this->platform];
    }

    /** Push the current state to the database session. */
    public function apply(?Connection $connection = null): void
    {
        $connection ??= $this->db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select(
            "select set_config('app.tenant_id', ?, false), set_config('app.context', ?, false)",
            [$this->tenant->id ?? '', $this->platform ? 'platform' : ($this->tenant ? 'tenant' : '')],
        );
    }

    private function restore(?Tenant $tenant, bool $platform): void
    {
        $this->tenant = $tenant;
        $this->platform = $platform;
        $this->apply();
    }
}
