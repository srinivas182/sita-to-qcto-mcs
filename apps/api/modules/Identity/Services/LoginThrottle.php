<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Contracts\Cache\Repository;
use Modules\Identity\Exceptions\AuthError;

/**
 * Lock-out with growing back-off per account key (e.g. email + tenant). After every
 * `max_attempts` failures the next lock is longer: 1 min, 5 min, 15 min, 1 h.
 * Successful sign-in clears the counter.
 */
final class LoginThrottle
{
    public function __construct(private readonly Repository $cache) {}

    public function ensureAllowed(string $key): void
    {
        $until = $this->cache->get($this->lockKey($key));

        if (is_int($until) && $until > time()) {
            throw AuthError::throttled($until - time());
        }
    }

    public function failed(string $key): void
    {
        $failures = (int) $this->cache->increment($this->countKey($key));
        $this->cache->put($this->countKey($key), $failures, 86400);

        $max = (int) config('identity.login.max_attempts', 5);

        if ($failures % $max === 0) {
            /** @var list<int> $steps */
            $steps = config('identity.login.lockouts', [60, 300, 900, 3600]);
            $index = min(intdiv($failures, $max) - 1, count($steps) - 1);
            $this->cache->put($this->lockKey($key), time() + $steps[$index], $steps[$index]);
        }
    }

    public function cleared(string $key): void
    {
        $this->cache->forget($this->countKey($key));
        $this->cache->forget($this->lockKey($key));
    }

    private function countKey(string $key): string
    {
        return 'login:fail:'.$key;
    }

    private function lockKey(string $key): string
    {
        return 'login:lock:'.$key;
    }
}
