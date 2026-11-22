<?php

declare(strict_types=1);

namespace Modules\Billing\Exceptions;

use RuntimeException;

final class BillingError extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
