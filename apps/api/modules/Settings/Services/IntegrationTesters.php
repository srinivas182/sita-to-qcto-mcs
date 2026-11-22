<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Illuminate\Contracts\Container\Container;
use Modules\Settings\Contracts\IntegrationTester;

/**
 * Registry of connection testers by driver. The module that implements a type
 * registers its testers (Files registers storage, Billing payment, Messaging sms).
 */
final class IntegrationTesters
{
    /** @var array<string, class-string<IntegrationTester>> */
    private array $testers = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<IntegrationTester>  $tester */
    public function register(string $driver, string $tester): void
    {
        $this->testers[$driver] = $tester;
    }

    public function has(string $driver): bool
    {
        return isset($this->testers[$driver]);
    }

    public function for(string $driver): ?IntegrationTester
    {
        if (! isset($this->testers[$driver])) {
            return null;
        }

        /** @var IntegrationTester */
        return $this->container->make($this->testers[$driver]);
    }
}
