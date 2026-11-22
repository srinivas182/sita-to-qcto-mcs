<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

/**
 * Standard Webhooks / Svix signatures (used by Yoco and Stitch):
 * base64(HMAC-SHA256(secret, "{id}.{timestamp}.{raw body}")), where the secret is
 * the base64 part after "whsec_". The header holds space-separated "v1,<sig>"
 * entries (several during key rotation). Old timestamps are refused (replays).
 */
final class StandardWebhook
{
    public static function verify(string $id, string $timestamp, string $header, string $body, string $secret, int $tolerance = 300): bool
    {
        if ($id === '' || $timestamp === '' || $header === '' || $secret === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        foreach (explode(' ', $header) as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
