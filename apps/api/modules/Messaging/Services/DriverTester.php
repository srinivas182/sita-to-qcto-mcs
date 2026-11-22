<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use Modules\Settings\Contracts\IntegrationTester;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\TestResult;

/** A read-only call that proves the keys work. Sends nothing. */
final class DriverTester implements IntegrationTester
{
    public function __construct(private readonly Drivers $drivers) {}

    public function test(Integration $integration): TestResult
    {
        $driver = $this->drivers->make($integration);

        if (! method_exists($driver, 'ping')) {
            return TestResult::failed('No connection test for this provider; use "Send a test message".');
        }

        $driver->ping();

        return TestResult::ok('Keys accepted.');
    }
}
