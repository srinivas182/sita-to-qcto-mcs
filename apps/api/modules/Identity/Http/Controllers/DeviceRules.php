<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

/** Validation rules for the device block sent by the app with sign-in requests. */
final class DeviceRules
{
    /**
     * The validated device block, typed for SignIn::complete().
     *
     * @return array{platform: string, name: string, public_key: string, push_token?: string|null}|null
     */
    public static function payload(mixed $device): ?array
    {
        if (! is_array($device) || ! isset($device['platform'], $device['name'], $device['public_key'])) {
            return null;
        }

        return [
            'platform' => (string) $device['platform'],
            'name' => (string) $device['name'],
            'public_key' => (string) $device['public_key'],
            'push_token' => isset($device['push_token']) ? (string) $device['push_token'] : null,
        ];
    }

    /** @return array<string, list<string>> */
    public static function nested(string $prefix = 'device.'): array
    {
        return [
            $prefix.'platform' => ['required_with:device', 'in:ios,android'],
            $prefix.'name' => ['required_with:device', 'string', 'max:100'],
            $prefix.'public_key' => ['required_with:device', 'string', 'max:64'],
            $prefix.'push_token' => ['nullable', 'string', 'max:512'],
        ];
    }
}
