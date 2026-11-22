<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use RuntimeException;

final class CrossTenantWrite extends RuntimeException
{
    public static function for(string $model, ?string $expected, ?string $given): self
    {
        return new self(sprintf(
            'Refusing to write %s for tenant [%s] while acting as tenant [%s].',
            $model,
            $given ?? 'none',
            $expected ?? 'none',
        ));
    }
}
