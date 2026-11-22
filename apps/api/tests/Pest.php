<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Cell;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Run a callback in the platform (cross-tenant) context.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asPlatform(Closure $callback): mixed
{
    return app(TenantContext::class)->runAsPlatform($callback);
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->runAs($tenant, $callback);
}

/** @param  array<string, mixed>  $attributes */
function makeTenant(array $attributes = []): Tenant
{
    return asPlatform(function () use ($attributes): Tenant {
        $cell = Cell::query()->find(config('platform.cell')) ?? Cell::factory()->create(['id' => config('platform.cell')]);

        return Tenant::factory()->create(['cell_id' => $cell->id, ...$attributes]);
    });
}
