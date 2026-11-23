<?php

declare(strict_types=1);

namespace Modules\Delivery\Support;

/**
 * The check-in code a facilitator shows: "<session>.<window>.<signature>", where the window
 * is the 30-second slot it was made in and the signature is an HMAC with a key per
 * provider. It cannot be made without the key, and an old one stops working, so a photo
 * sent to someone at home is useless a minute later.
 */
final class QrToken
{
    public const WINDOW_SECONDS = 30;

    public static function make(string $tenantId, string $sessionId, int $at): string
    {
        $window = intdiv($at, self::WINDOW_SECONDS);

        return "{$sessionId}.{$window}.".self::sign($tenantId, $sessionId, $window);
    }

    /** @return array{session_id: string, window: int}|null when the signature is right */
    public static function read(string $tenantId, string $token): ?array
    {
        if (! preg_match('/^([0-9A-HJKMNP-TV-Z]{26})\.(\d{1,12})\.([0-9a-f]{20})$/i', trim($token), $m)) {
            return null;
        }
        $window = (int) $m[2];

        return hash_equals(self::sign($tenantId, strtolower($m[1]), $window), strtolower($m[3])) ? ['session_id' => strtolower($m[1]), 'window' => $window] : null;
    }

    /** Seconds since the start of the token's window. */
    public static function age(int $window, int $at): int
    {
        return $at - $window * self::WINDOW_SECONDS;
    }

    private static function sign(string $tenantId, string $sessionId, int $window): string
    {
        $key = hash_hmac('sha256', 'attendance-qr|'.$tenantId, (string) config('app.key'));

        return substr(hash_hmac('sha256', "{$sessionId}|{$window}", $key), 0, 20);
    }
}
