<?php

declare(strict_types=1);

namespace Modules\Access\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Modules\Access\Models\Invitation;
use Modules\Access\Services\InvitationService;
use Modules\Identity\Http\Controllers\DeviceRules;
use Modules\Identity\Models\User;
use Modules\Identity\Services\SignIn;
use Modules\Tenancy\Context\TenantContext;

final class InvitationController
{
    public function index(): JsonResponse
    {
        Gate::authorize('tenant.users.invite');

        return response()->json(['data' => Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->latest()->limit(200)->get()->map(fn (Invitation $i): array => $this->present($i))]);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        abort_unless($this->context()->tenantId() === $tenant, 404);
        Gate::authorize('tenant.users.invite');

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
        $result = $this->invitations()->invite($actor, $data);

        return response()->json($this->present($result['invitation']), 201);
    }

    public function destroy(string $invitation): Response
    {
        Gate::authorize('tenant.users.invite');

        $this->invitations()->revoke(Invitation::query()->findOrFail($invitation));

        return response()->noContent();
    }

    /** Public: what the acceptance screen shows. */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations()->find($token);

        return response()->json([
            'tenant' => $this->context()->tenant()?->trading_name,
            'role' => $invitation->role,
            'role_label' => config("access.roles.{$invitation->role}.label"),
            'email' => $invitation->email,
            'first_name' => $invitation->first_name,
            'last_name' => $invitation->last_name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /** Public or signed-in: accept and start a session (web) or tokens (app). */
    public function accept(Request $request, string $token): JsonResponse
    {
        $current = $request->user('sanctum');
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'password' => [$current === null ? 'nullable' : 'prohibited', 'string', Password::defaults()],
            'device' => ['nullable', 'array'],
            'device.platform' => ['required_with:device', 'in:ios,android'],
            'device.name' => ['required_with:device', 'string', 'max:100'],
            'device.public_key' => ['required_with:device', 'string', 'max:64'],
        ]);

        $user = $this->invitations()->accept($token, $current instanceof User ? $current : null, $data);

        return response()->json([
            'accepted' => true,
            ...$this->signIn()->complete($request, $user, 'invitation', DeviceRules::payload($data['device'] ?? null)),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function present(Invitation $i): array
    {
        return [
            'id' => $i->id,
            'email' => $i->email,
            'phone' => $i->phone_e164,
            'role' => $i->role,
            'centre_id' => $i->centre_id,
            'expires_at' => $i->expires_at->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function invitations(): InvitationService
    {
        return app(InvitationService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function signIn(): SignIn
    {
        return app(SignIn::class);
    }
}
