<?php

declare(strict_types=1);

namespace Modules\Access\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Catalogue;
use Modules\Access\Exceptions\AccessError;
use Modules\Access\Models\RoleAssignment;
use Modules\Access\Services\AccessService;
use Modules\Access\Services\Directory;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\PhoneNumber;

final class RoleController
{
    /** The role catalogue, marking which roles the caller may hand out here. */
    public function roles(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => collect($this->catalogue()->roles())->map(fn (array $r, string $key): array => [
            'key' => $key,
            'label' => $r['label'],
            'scope' => $r['scope'],
            'mfa' => $r['mfa'],
            'permissions' => $r['permissions'],
            'assignable' => $this->access()->mayDelegate($user, $key),
        ])->values()]);
    }

    public function users(Request $request): JsonResponse
    {
        Gate::authorize('tenant.users.view');

        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->directory()->members($data['search'] ?? null, $data['role'] ?? null, $this->access()->centresFor($actor, 'tenant.users.view'), (int) ($data['per_page'] ?? 25));
        $roles = RoleAssignment::query()->whereIn('user_id', $page->getCollection()->pluck('user_id'))->get()->groupBy('user_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (UserTenant $m): array => [
                'id' => $m->user_id,
                'name' => $m->user?->fullName(),
                'email' => $m->user?->email,
                'phone' => $m->user?->phone_e164 !== null ? PhoneNumber::mask($m->user->phone_e164) : null,
                'status' => $m->status->value,
                'centre_id' => $m->centre_id,
                'joined_at' => $m->joined_at?->toIso8601String(),
                'roles' => ($roles[$m->user_id] ?? collect())->map(fn ($a): array => ['role' => $a->role, 'centre_id' => $a->centre_id])->values(),
            ])->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Replace a member's roles in this provider. */
    public function sync(Request $request, string $user): JsonResponse
    {
        Gate::authorize('tenant.roles.assign');

        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validate([
            'roles' => ['present', 'array', 'max:20'],
            'roles.*.role' => ['required', 'string', 'max:40'],
            'roles.*.centre_id' => ['nullable', 'string', 'size:26'],
        ]);

        $target = User::query()->find($user) ?? abort(404);

        if ($target->id === $actor->id) {
            throw AccessError::cannotDelegate('your own roles');
        }

        $current = $this->access()->tenantAssignments($target)->pluck('role')->all();
        $wanted = array_column($data['roles'], 'role');

        foreach (array_unique([...array_diff($current, $wanted), ...array_diff($wanted, $current)]) as $changed) {
            if (! $this->access()->mayDelegate($actor, $changed)) {
                throw AccessError::cannotDelegate($changed);
            }
        }

        /** @var list<array{role: string, centre_id?: string|null}> $roles */
        $roles = $data['roles'];
        $this->access()->sync($target, $roles, $actor);

        return response()->json(['roles' => $this->access()->tenantAssignments($target)->map(fn ($a): array => ['role' => $a->role, 'centre_id' => $a->centre_id])->values()]);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function catalogue(): Catalogue
    {
        return app(Catalogue::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function access(): AccessService
    {
        return app(AccessService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function directory(): Directory
    {
        return app(Directory::class);
    }
}
