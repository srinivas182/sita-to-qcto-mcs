<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Audit\AuditLogger;
use Modules\Identity\Devices\DeviceService;
use Modules\Identity\Devices\TokenIssuer;
use Modules\Identity\Http\Resources\MeResource;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\RefreshToken;
use Modules\Identity\Models\User;

final class SessionController
{
    public function me(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $request->bearerToken() !== null ? $user->currentAccessToken() : null;

        if ($token !== null) {
            // App sign-out ends the device session (but keeps the device record).
            if ($token->device_id !== null) {
                $device = Device::query()->find($token->device_id);
                $device?->forceFill(['offline_until' => null])->save();
                RefreshToken::query()->where('device_id', $token->device_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }

            $token->delete();
        } else {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        $this->audit()->record('auth.signed_out', $user);

        return response()->noContent();
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string', 'max:120'],
            'device_id' => ['required', 'string', 'size:26'],
            'timestamp' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:120'],
        ]);

        return response()->json($this->tokens()->refresh($data['refresh_token'], $data['device_id'], (int) $data['timestamp'], $data['signature']));
    }

    public function devices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentDevice = $request->bearerToken() !== null ? $user->currentAccessToken()->device_id : null;

        return response()->json(['data' => $user->devices()->whereNull('revoked_at')->orderByDesc('last_seen_at')->get()->map(fn (Device $d): array => [
            'id' => $d->id,
            'platform' => $d->platform,
            'name' => $d->name,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'offline_until' => $d->offline_until?->toIso8601String(),
            'current' => $d->id === $currentDevice,
        ])]);
    }

    /** Update push token / name of the calling device. */
    public function updateDevice(Request $request, string $device): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'push_token' => ['sometimes', 'nullable', 'string', 'max:512']]);

        /** @var User $user */
        $user = $request->user();
        $record = $this->deviceService()->find($user, $device) ?? abort(404);
        $record->fill($data)->save();

        return response()->json(['id' => $record->id]);
    }

    /** Sign a device out and trigger remote wipe. */
    public function revokeDevice(Request $request, string $device): Response
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->deviceService()->find($user, $device) ?? abort(404);
        $this->deviceService()->revoke($record);

        return response()->noContent();
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function deviceService(): DeviceService
    {
        return app(DeviceService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function tokens(): TokenIssuer
    {
        return app(TokenIssuer::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }
}
