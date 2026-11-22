<?php

declare(strict_types=1);

namespace Modules\Access\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Access\Catalogue;
use Modules\Access\Models\PlatformRoleAssignment;
use Modules\Access\Services\AccessService;
use Modules\Access\Services\InvitationService;
use Modules\Identity\Models\User;
use Modules\Identity\Services\PasswordReset;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Super Admin: platform staff and first-owner invitations. Platform context. */
final class StaffController
{
    public function index(): JsonResponse
    {
        $assignments = PlatformRoleAssignment::query()->orderBy('role')->get()->groupBy('user_id');
        $users = User::query()->whereIn('id', $assignments->keys())->orderBy('last_name')->get();

        return response()->json(['data' => $users->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->fullName(),
            'email' => $u->email,
            'roles' => $assignments->get($u->id)?->pluck('role')->values() ?? [],
            'last_login_at' => $u->last_login_at?->toIso8601String(),
        ])->values()]);
    }

    /** Grant a platform role; creates the person if new and emails a set-password link. */
    public function store(Request $request, PasswordReset $reset): JsonResponse
    {
        $platformRoles = array_keys(array_filter($this->catalogue()->roles(), fn (array $r): bool => $r['scope'] === 'platform'));
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => ['required', 'in:'.implode(',', $platformRoles)],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $email = strtolower($data['email']);
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first()
            ?? User::query()->create(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $email]);

        $this->access()->grantPlatformRole($user, $data['role'], $actor);

        if ($user->password === null) {
            $reset->send($email, $request->getHost());
        }

        return response()->json(['id' => $user->id, 'role' => $data['role']], 201);
    }

    public function destroy(Request $request, string $user, string $role): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_if($actor->id === $user && $role === 'super-admin', 422, 'You cannot remove your own Super Admin role.');

        $this->access()->revokePlatformRole(User::query()->findOrFail($user), $role);

        return response()->noContent();
    }

    /** Invite the first owner (or any role) into a provider on its behalf. */
    public function inviteToTenant(Request $request, InvitationService $invitations, string $tenant): JsonResponse
    {
        $target = Tenant::query()->findOrFail($tenant);
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:20'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', 'string', 'max:40'],
            'centre_id' => ['nullable', 'string', 'size:26'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $result = $this->context()->runAs($target, fn (): array => $invitations->invite($actor, $data));

        return response()->json(['id' => $result['invitation']->id, 'expires_at' => $result['invitation']->expires_at->toIso8601String()], 201);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function access(): AccessService
    {
        return app(AccessService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function catalogue(): Catalogue
    {
        return app(Catalogue::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
