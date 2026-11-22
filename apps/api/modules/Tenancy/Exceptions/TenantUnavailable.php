<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use RuntimeException;

/** A queued job's tenant no longer exists or is suspended; the job must not run. */
final class TenantUnavailable extends RuntimeException
{
    public static function forJob(string $tenantId): self
    {
        return new self("Tenant [{$tenantId}] is unavailable; job not run.");
    }
}
