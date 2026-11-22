<?php

declare(strict_types=1);

namespace Modules\Settings\Contracts;

use Modules\Settings\Models\Integration;
use Modules\Settings\Services\TestResult;

/**
 * Checks that an integration's credentials work, e.g. by writing and deleting a
 * probe file or reading the account balance. Must not charge money or send messages.
 */
interface IntegrationTester
{
    public function test(Integration $integration): TestResult;
}
