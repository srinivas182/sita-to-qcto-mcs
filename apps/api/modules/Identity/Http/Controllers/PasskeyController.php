<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Identity\Models\Passkey;
use Modules\Identity\Models\User;
use Modules\Identity\Passkeys\PasskeyService;
use Modules\Identity\Services\SignIn;
use Modules\Tenancy\Context\TenantContext;

final class PasskeyController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $user->passkeys()->orderBy('created_at')->get()->map(fn (Passkey $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'created_at' => $p->created_at?->toIso8601String(),
            'last_used_at' => $p->last_used_at?->toIso8601String(),
        ])]);
    }

    public function options(Request $request): JsonResponse
    {
        $rpName = $this->context()->tenant()->trading_name ?? (string) config('app.name');

        return response()->json($this->passkeys()->registrationOptions($this->user($request), $request->getHost(), $rpName));
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'array'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $passkey = $this->passkeys()->register(
            $this->user($request),
            (string) json_encode($data['credential']),
            $this->origin($request),
            $request->getHost(),
            $data['name'] ?? 'Passkey',
        );

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name], 201);
    }

    public function destroy(Request $request, string $passkey): Response
    {
        $this->user($request)->passkeys()->whereKey($passkey)->firstOrFail()->delete();

        return response()->noContent();
    }

    public function loginOptions(Request $request): JsonResponse
    {
        return response()->json($this->passkeys()->loginOptions($request->getHost()));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => ['required', 'string', 'max:64'],
            'credential' => ['required', 'array'],
            'remember' => ['sometimes', 'boolean'],
            'device' => ['nullable', 'array'],
            ...DeviceRules::nested(),
        ]);

        $user = $this->passkeys()->login($data['session'], (string) json_encode($data['credential']), $this->origin($request), $request->getHost());

        return response()->json($this->signIn()->complete($request, $user, 'passkey', DeviceRules::payload($data['device'] ?? null), (bool) ($data['remember'] ?? false)));
    }

    private function origin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function passkeys(): PasskeyService
    {
        return app(PasskeyService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function signIn(): SignIn
    {
        return app(SignIn::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
