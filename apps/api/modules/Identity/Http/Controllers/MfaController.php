<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Mfa\TotpService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

final class MfaController
{
    public function enable(Request $request): JsonResponse
    {
        $issuer = $this->context()->tenant()->trading_name ?? (string) config('app.name');

        return response()->json($this->totp()->begin($this->user($request), $issuer));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return response()->json(['recovery_codes' => $this->totp()->confirm($this->user($request), $data['code'])]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $this->user($request);

        if (! $this->totp()->verify($user, $data['code'])) {
            throw AuthError::invalidCode();
        }

        return response()->json(['recovery_codes' => $this->totp()->regenerateRecoveryCodes($user)]);
    }

    public function disable(Request $request): Response
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $this->user($request);

        if (! $this->totp()->verify($user, $data['code'])) {
            throw AuthError::invalidCode();
        }

        $this->totp()->disable($user);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function totp(): TotpService
    {
        return app(TotpService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
