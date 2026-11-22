<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\DB;
use Modules\Access\Models\CustomRole;
use Modules\Access\Services\AccessService;
use Modules\Tenancy\Models\Centre;

beforeEach(function (): void {
    $this->tym = makeProvider('tym');
    $this->thabo = makeMember($this->tym, [], ['first_name' => 'Thabo', 'email' => 'thabo@tym.test', 'password' => 'correct horse battery staple']);
});

it('lets owners build a role from their own permissions, and it works everywhere', function (): void {
    signInOwner($this, $this->tym);
    $roles = $this->getJson(TYM_V1.'/roles/custom')->assertOk();
    expect(collect($roles->json('permissions'))->pluck('key'))->toContain('credentials.view')->not->toContain('platform.tenants.manage')->not->toContain('tenant.roles.manage')
        ->and(collect($roles->json('templates'))->pluck('key'))->toContain('centre-manager');

    $this->postJson(TYM_V1.'/roles/custom', ['name' => 'Bad', 'scope' => 'tenant', 'permissions' => ['platform.tenants.manage']])->assertStatus(422);
    $this->postJson(TYM_V1.'/roles/custom', ['name' => 'Centre manager', 'scope' => 'tenant', 'permissions' => ['credentials.view']])->assertStatus(422)->assertJsonValidationErrors('name');

    $role = $this->postJson(TYM_V1.'/roles/custom', ['name' => 'Quality officer', 'description' => 'Checks registrations', 'scope' => 'tenant',
        'permissions' => ['credentials.view', 'tenant.users.view'], 'based_on' => 'centre-manager'])->assertCreated()->json('data');
    expect($role['key'])->toStartWith('custom:')->and($role['mfa'])->toBeTrue(); // tenant.users.view is administrative

    // It is listed with the standard roles and can be given like one.
    $this->getJson(TYM_V1.'/roles')->assertJsonFragment(['key' => $role['key'], 'label' => 'Quality officer', 'custom' => true, 'assignable' => true]);
    asTenant($this->tym, fn () => app(AccessService::class)->assign($this->thabo, $role['key']));
    expect(asTenant($this->tym, fn () => app(AccessService::class)->can($this->thabo, 'credentials.view')))->toBeTrue()
        ->and(asTenant($this->tym, fn () => app(AccessService::class)->can($this->thabo, 'credentials.manage')))->toBeFalse();

    // Held roles cannot be deleted or moved between provider-wide and per-centre.
    $this->deleteJson(TYM_V1."/roles/custom/{$role['id']}")->assertStatus(422);
    $this->patchJson(TYM_V1."/roles/custom/{$role['id']}", ['scope' => 'centre'])->assertStatus(422);

    // Narrowing the permissions takes effect at once.
    $this->patchJson(TYM_V1."/roles/custom/{$role['id']}", ['permissions' => ['tenant.users.view']])->assertOk();
    expect(asTenant($this->tym, fn () => app(AccessService::class)->can($this->thabo, 'credentials.view')))->toBeFalse();
});

it('keeps one provider\'s roles away from another', function (): void {
    $other = makeProvider('other');
    $key = asTenant($this->tym, fn () => CustomRole::query()->create(['key' => 'custom:01jtestroleaaaaaaaaaaaaaaaa', 'name' => 'Quality officer', 'scope' => 'tenant', 'permissions' => ['credentials.view'], 'mfa' => false])->key);
    $stranger = makeMember($other, [], ['email' => 'stranger@other.test']);

    expect(asTenant($other, fn () => CustomRole::query()->count()))->toBe(0);
    expect(fn () => asTenant($other, fn () => app(AccessService::class)->assign($stranger, $key)))->toThrow(Exception::class);
    expect(asTenant($other, fn () => DB::table('custom_roles')->where('key', $key)->update(['permissions' => '["tenant.billing.manage"]'])))->toBe(0);
});

it('keeps custom roles for owners to hand out', function (): void {
    asTenant($this->tym, fn () => CustomRole::query()->create(['key' => 'custom:01jtestroleaaaaaaaaaaaaaaab', 'name' => 'Helper', 'scope' => 'centre', 'permissions' => ['learners.view'], 'mfa' => false]));
    $centre = asTenant($this->tym, fn () => Centre::query()->create(['name' => 'Soweto']));
    $manager = makeMember($this->tym, [], ['email' => 'mgr@tym.test', 'password' => 'correct horse battery staple']);
    asTenant($this->tym, fn () => app(AccessService::class)->assign($manager, 'centre-manager', $centre->id));

    expect(asTenant($this->tym, fn () => app(AccessService::class)->mayDelegate($manager, 'custom:01jtestroleaaaaaaaaaaaaaaab')))->toBeFalse();
    signInAs($this, 'mgr@tym.test');
    $this->postJson(TYM_V1.'/roles/custom', ['name' => 'Sneaky', 'scope' => 'tenant', 'permissions' => ['tenant.billing.manage']])->assertForbidden();
});
