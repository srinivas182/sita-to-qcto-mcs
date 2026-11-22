<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Modules\Identity\Services\PasswordLogin;
use Modules\Identity\Services\PasswordReset;
use Modules\Identity\Services\SignIn;

/** Staff sign-in: email + password, then authenticator code if set up. */
final class PasswordController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
            'device' => ['nullable', 'array'],
            ...DeviceRules::nested(),
        ]);

        $continuation = ['device' => DeviceRules::payload($data['device'] ?? null), 'remember' => (bool) ($data['remember'] ?? false)];
        $result = $this->passwordLogin()->attempt($data['email'], $data['password'], $continuation);

        // Check access before revealing that a second factor is needed.
        $this->signIn()->ensureMayEnter($result['user']);

        if ($result['mfa_token'] !== null) {
            return response()->json(['mfa_required' => true, 'mfa_token' => $result['mfa_token'], 'methods' => ['totp', 'recovery_code']]);
        }

        return response()->json($this->signIn()->complete($request, $result['user'], 'password', DeviceRules::payload($continuation['device']), $continuation['remember']));
    }

    public function forgot(Request $request, PasswordReset $reset): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $reset->send($data['email'], $request->getHost());

        return response()->json(['message' => 'If the email belongs to an account here, a reset link is on its way.'], 202);
    }

    public function reset(Request $request, PasswordReset $reset): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $reset->reset($data['email'], $data['token'], $data['password']);

        return response()->json(['message' => 'Password changed. You can sign in now.']);
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $result = $this->passwordLogin()->completeMfa($data['mfa_token'], $data['code']);
        $device = $result['continuation']['device'] ?? null;

        return response()->json($this->signIn()->complete(
            $request,
            $result['user'],
            'password+totp',
            DeviceRules::payload($device),
            (bool) ($result['continuation']['remember'] ?? false),
        ));
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function passwordLogin(): PasswordLogin
    {
        return app(PasswordLogin::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function signIn(): SignIn
    {
        return app(SignIn::class);
    }
}
