<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Otp\OtpService;
use Modules\Identity\Services\SignIn;

/** Learner sign-in with a phone PIN (WhatsApp first, SMS fallback). */
final class OtpController
{
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'size:2'],
            'channel' => ['nullable', 'in:whatsapp,sms'],
        ]);

        return response()->json($this->otp()->request(
            $data['identifier'],
            $data['country'] ?? null,
            $data['channel'] ?? null,
            $request->ip(),
        ), 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'max:40'],
            'code' => ['required', 'string', 'max:10'],
            'device' => ['nullable', 'array'],
            ...DeviceRules::nested(),
        ]);

        $user = $this->otp()->verify($data['challenge_id'], $data['code'], $request->ip());

        return response()->json($this->signIn()->complete($request, $user, 'otp', DeviceRules::payload($data['device'] ?? null)));
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function otp(): OtpService
    {
        return app(OtpService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function signIn(): SignIn
    {
        return app(SignIn::class);
    }
}
