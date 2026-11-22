<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Modules\Settings\Contracts\IntegrationTester;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\TestResult;

/** Makes a read-only call with the saved keys. No money moves. */
final class GatewayTester implements IntegrationTester
{
    public function __construct(private readonly Gateways $gateways) {}

    public function test(Integration $integration): TestResult
    {
        $gateway = $this->gateways->make($integration);

        if (! method_exists($gateway, 'ping')) {
            return TestResult::failed('No connection test for this gateway yet.');
        }

        $gateway->ping();

        return TestResult::ok(($integration->isSandbox() ? 'Sandbox' : 'Live').' keys accepted.');
    }
}
