<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;

/** The phone's push token, kept on its device record (one token per device). */
final class PushTokenController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200', 'regex:/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]{10,}\]$/']], [
            'token.regex' => 'That is not an Expo push token.',
        ]);
        $device = $this->device($request);
        $device->forceFill(['push_token' => $data['token']])->save();

        return response()->json(['registered' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->device($request)->forceFill(['push_token' => null])->save();

        return response()->json(['registered' => false]);
    }

    /** Settings → "Send a test notification". */
    public function test(Request $request, PushService $push): JsonResponse
    {
        $device = $this->device($request);
        abort_if($device->push_token === null, 422, 'Turn on notifications first.');

        /** @var User $user */
        $user = $request->user();
        $sent = $push->send($device, 'Notifications work', "Hi {$user->first_name}, you'll get reminders and results here.", ['type' => 'test']);

        return response()->json(['sent' => $sent], $sent ? 200 : 502);
    }

    /** Push tokens belong to a phone: only device (app) sessions have one. */
    private function device(Request $request): Device
    {
        /** @var User $user */
        $user = $request->user();
        // Browser sessions carry a transient token with no device (null below).
        $deviceId = $user->currentAccessToken()->device_id ?? null;
        abort_if($deviceId === null, 422, 'Push notifications are for the mobile app.');

        return Device::query()->whereKey($deviceId)->where('user_id', $user->id)->whereNull('revoked_at')->firstOrFail();
    }
}
