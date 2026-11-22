<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

use Modules\Settings\Models\Integration;

/**
 * For providers that do not sign their callbacks, the callback URL carries a secret
 * key derived from the app key and the integration. Only the provider (which we gave
 * the URL to) can know it.
 */
final class CallbackKey
{
    public static function for(Integration $integration): string
    {
        return substr(hash_hmac('sha256', 'messaging-callback:'.$integration->driver.':'.$integration->id, (string) config('app.key')), 0, 40);
    }

    public static function url(Integration $integration): string
    {
        $base = rtrim((string) config('billing.webhook_base', config('app.url')), '/');

        return $base.'/v1/webhooks/messaging/'.$integration->driver.'?key='.self::for($integration);
    }

    public static function valid(Integration $integration, ?string $given): bool
    {
        return is_string($given) && hash_equals(self::for($integration), $given);
    }
}
