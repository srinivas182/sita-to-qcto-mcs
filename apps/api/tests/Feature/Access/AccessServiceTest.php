<?php

declare(strict_types=1);

use Modules\Access\Exceptions\AccessError;
use Modules\Access\Services\AccessService;
use Modules\Tenancy\Models\Centre;

beforeEach(function (): void {
    $this->tym = makeProvider('tym');
    $this->other = makeProvider('other');
    [$this->soweto, $this->durban] = asPlatform(fn () => [
        Centre::factory()->create(['tenant_id' => $this->tym->id, 'name' => 'Soweto']),
        Centre::factory()->create(['tenant_id' => $this->tym->id, 'name' => 'Durban']),
    ]);
    $this->foreignCentre = asPlatform(fn () => Centre::factory()->create(['tenant_id' => $this->other->id]));
    $this->access = app(AccessService::class);
});

it('scopes centre roles to their centre', function (): void {
    $facilitator = makeMember($this->tym, [['facilitator', $this->soweto->id]]);

    asTenant($this->tym, function () use ($facilitator): void {
        expect($this->access->can($facilitator, 'learning.deliver'))->toBeTrue()
            ->and($this->access->can($facilitator, 'learning.deliver', $this->soweto->id))->toBeTrue()
            ->and($this->access->can($facilitator, 'learning.deliver', $this->durban->id))->toBeFalse()
            ->and($this->access->can($facilitator, 'tenant.settings.manage'))->toBeFalse()
            ->and($this->access->centresFor($facilitator, 'learners.view'))->toBe([$this->soweto->id]);
    });
});

it('gives tenant roles every centre and nothing elsewhere', function (): void {
    $owner = makeMember($this->tym, ['tp-owner']);

    asTenant($this->tym, fn () => expect($this->access->centresFor($owner, 'learners.view'))->toBeNull()
        ->and($this->access->can($owner, 'learners.view', $this->durban->id))->toBeTrue());

    asTenant($this->other, fn () => expect($this->access->can($owner, 'learners.view'))->toBeFalse());
});

it('rejects roles in the wrong place', function (string $role, ?string $centre, string $reason): void {
    $user = makeMember($this->tym);
    $centreId = match ($centre) {
        'foreign' => $this->foreignCentre->id,
        'own' => $this->soweto->id,
        default => null,
    };

    try {
        asTenant($this->tym, fn () => $this->access->assign($user, $role, $centreId));
        $this->fail('Expected AccessError');
    } catch (AccessError $e) {
        expect($e->reason)->toBe($reason);
    }
})->with([
    'platform role in a provider' => ['super-admin', null, 'role_scope'],
    'unknown role' => ['wizard', null, 'role_unknown'],
    'owner limited to a centre' => ['tp-owner', 'own', 'role_centre'],
    'centre of another provider' => ['facilitator', 'foreign', 'centre_unknown'],
]);

it('only assigns roles to members', function (): void {
    $stranger = makeMember($this->other);

    asTenant($this->tym, fn () => $this->access->assign($stranger, 'learner'));
})->throws(AccessError::class, 'not a member');

it('keeps at least one owner', function (): void {
    $owner = makeMember($this->tym, ['tp-owner']);

    asTenant($this->tym, fn () => $this->access->revoke($owner, 'tp-owner'));
})->throws(AccessError::class, 'at least one owner');

it('lets owners delegate anything local and centre managers only below themselves', function (): void {
    $owner = makeMember($this->tym, ['tp-owner']);
    $manager = makeMember($this->tym, [['centre-manager', $this->soweto->id]]);
    $admin = makeStaff('super-admin');

    asTenant($this->tym, function () use ($owner, $manager, $admin): void {
        expect($this->access->mayDelegate($owner, 'tp-owner'))->toBeTrue()
            ->and($this->access->mayDelegate($owner, 'super-admin'))->toBeFalse()
            ->and($this->access->mayDelegate($manager, 'facilitator'))->toBeTrue()
            ->and($this->access->mayDelegate($manager, 'centre-manager'))->toBeFalse()
            ->and($this->access->mayDelegate($manager, 'tp-owner'))->toBeFalse()
            ->and($this->access->mayDelegate($admin, 'tp-owner'))->toBeTrue();
    });
});

it('keeps platform roles out of providers', function (): void {
    $admin = makeStaff('super-admin');

    expect(DB::selectOne('select count(*) as n from platform_role_assignments')->n)->toBe(0);
    asTenant($this->tym, fn () => expect(DB::selectOne('select count(*) as n from platform_role_assignments')->n)->toBe(0));
    expect($this->access->platformRoles($admin))->toBe(['super-admin']);
});

it('defines all 17 roles from the journeys', function (): void {
    expect(array_keys(config('access.roles')))->toHaveCount(17)
        ->and(collect(config('access.roles'))->pluck('permissions')->flatten()->unique()
            ->diff(array_keys(config('access.permissions')))->all())->toBe([]);
});
