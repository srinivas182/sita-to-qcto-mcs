<?php

declare(strict_types=1);

namespace Modules\Access\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Catalogue;
use Modules\Access\Models\CustomRole;
use Modules\Access\Models\RoleAssignment;
use Modules\Access\Services\CustomRoleService;
use Modules\Identity\Models\User;

/** Owners build their own roles from the permission catalogue. */
final class CustomRoleController
{
    public function index(): JsonResponse
    {
        Gate::authorize('tenant.users.view');
        $catalogue = app(Catalogue::class);
        $held = RoleAssignment::query()->where('role', 'like', CustomRole::PREFIX.'%')
            ->selectRaw('role, count(distinct user_id) as n')->groupBy('role')->pluck('n', 'role');
        $permissions = (array) config('access.permissions', []);

        return response()->json([
            'data' => CustomRole::query()->orderBy('name')->get()->map(fn (CustomRole $r) => [
                'id' => $r->id, 'key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'scope' => $r->scope,
                'permissions' => $r->permissions, 'mfa' => $r->mfa, 'based_on' => $r->based_on, 'people' => (int) ($held[$r->key] ?? 0),
            ])->all(),
            // What a role may be built from, with plain descriptions, and the standard roles to start from.
            'permissions' => collect($catalogue->customisablePermissions())->map(fn (string $p) => ['key' => $p, 'label' => $permissions[$p] ?? $p])->values()->all(),
            'templates' => collect($catalogue->builtInRoles())->filter(fn (array $r) => $r['scope'] !== 'platform')
                ->map(fn (array $r, string $k) => ['key' => $k, 'label' => $r['label'], 'scope' => $r['scope'],
                    'permissions' => array_values(array_intersect($r['permissions'], $catalogue->customisablePermissions()))])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('tenant.roles.manage');
        $role = $this->service()->save($request->all(), null, $this->actor($request));

        return response()->json(['data' => ['id' => $role->id, 'key' => $role->key, 'mfa' => $role->mfa]], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        Gate::authorize('tenant.roles.manage');
        $model = $this->service()->save($request->all(), CustomRole::query()->findOrFail($role), $this->actor($request));

        return response()->json(['data' => ['id' => $model->id, 'key' => $model->key, 'mfa' => $model->mfa]]);
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        Gate::authorize('tenant.roles.manage');
        $this->service()->delete(CustomRole::query()->findOrFail($role), $this->actor($request));

        return response()->json(['deleted' => true]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function service(): CustomRoleService
    {
        return app(CustomRoleService::class);
    }
}
