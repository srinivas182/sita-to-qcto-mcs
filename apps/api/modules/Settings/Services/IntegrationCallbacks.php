<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Closure;
use Modules\Settings\Models\Integration;

/**
 * The URL each provider must call back (webhooks, delivery receipts), shown to the
 * Super Admin to paste into the provider's dashboard. Modules register a resolver per
 * driver.
 */
final class IntegrationCallbacks
{
    /** @var array<string, Closure(Integration): string> */
    private array $resolvers = [];

    /** @param  Closure(Integration): string  $resolver */
    public function register(string $driver, Closure $resolver): void
    {
        $this->resolvers[$driver] = $resolver;
    }

    public function url(Integration $integration): ?string
    {
        return isset($this->resolvers[$integration->driver]) ? ($this->resolvers[$integration->driver])($integration) : null;
    }
}
