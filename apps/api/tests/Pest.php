<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Services\AccessService;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\MembershipStatus;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Domains\DomainService;
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

/**
 * A provider with its platform subdomain (<code>.skills.test).
 *
 * @param  array<string, mixed>  $attributes
 */
function makeProvider(string $code, array $attributes = []): Tenant
{
    $tenant = makeTenant(['code' => $code, ...$attributes]);
    asPlatform(fn () => app(DomainService::class)->addSubdomain($tenant));

    return $tenant;
}

/**
 * A person who belongs to the provider with the given roles.
 *
 * @param  list<string|array{0: string, 1: string|null}>  $roles  'facilitator' or ['facilitator', $centreId]
 * @param  array<string, mixed>  $attributes
 */
function makeMember(Tenant $tenant, array $roles = [], array $attributes = []): User
{
    $user = asPlatform(fn (): User => User::factory()->create($attributes));

    asTenant($tenant, function () use ($user, $roles): void {
        UserTenant::query()->create(['user_id' => $user->id, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        foreach ($roles as $role) {
            [$name, $centre] = is_array($role) ? $role : [$role, null];
            app(AccessService::class)->assign($user, $name, $centre);
        }
    });

    app(AccessService::class)->forget($user);

    return $user;
}

/** @param  array<string, mixed>  $attributes */
function makeStaff(string $role = 'super-admin', array $attributes = []): User
{
    $user = asPlatform(fn (): User => User::factory()->create($attributes));
    app(AccessService::class)->grantPlatformRole($user, $role);

    return $user;
}

/** Headers of the same-origin web app on a host. */
function webOrigin(string $host): array
{
    return ['Origin' => 'https://'.$host, 'Referer' => 'https://'.$host.'/'];
}
