<?php

declare(strict_types=1);

namespace Modules\Identity\Push;

use Illuminate\Support\Facades\Http;
use Modules\Identity\Models\Device;

/**
 * Sends push notifications through Expo's push service, which delivers to Firebase (Android)
 * and APNs (iOS) for us. Tokens Expo reports as gone are cleared from the device.
 */
final class PushService
{
    /** @param  array<string, string>  $data */
    public function send(Device $device, string $title, string $body, array $data = []): bool
    {
        if ($device->push_token === null || $device->revoked_at !== null) {
            return false;
        }

        $request = Http::acceptJson()->asJson()->timeout(10)->connectTimeout(5);
        $token = config('services.expo.access_token');
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post('https://exp.host/--/api/v2/push/send', [[
            'to' => $device->push_token,
            'title' => $title,
            'body' => $body,
            'data' => (object) $data,
            'sound' => 'default',
            'priority' => 'high',
        ]]);

        $ticket = $response->json('data.0');
        if (! $response->successful() || ! is_array($ticket)) {
            return false;
        }

        if (($ticket['status'] ?? null) === 'error') {
            if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                $device->forceFill(['push_token' => null])->save(); // app removed or notifications off
            }

            return false;
        }

        return ($ticket['status'] ?? null) === 'ok';
    }
}
