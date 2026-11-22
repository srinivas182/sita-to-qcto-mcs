<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * The web app is served from the same host as the API (every tenant domain serves
 * both), so a request is "first-party" exactly when its Origin or Referer is the
 * request host. Such requests get cookie sessions and CSRF protection; everything
 * else must use a bearer token. No static allow-list is needed for custom domains.
 */
final class StatefulSameOrigin extends EnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request)
    {
        $source = $request->headers->get('origin') ?: $request->headers->get('referer');

        if (! is_string($source) || $source === '') {
            return false;
        }

        $host = parse_url($source, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) === 0;
    }
}
