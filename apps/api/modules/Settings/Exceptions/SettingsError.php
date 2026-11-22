<?php

declare(strict_types=1);

namespace Modules\Settings\Exceptions;

use RuntimeException;

/** A settings request that cannot be carried out; shown to the admin as is. */
final class SettingsError extends RuntimeException
{
    /** @param  array<string, array<string>>  $errors  field => messages */
    public function __construct(string $message, public readonly string $reason, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
