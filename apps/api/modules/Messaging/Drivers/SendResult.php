<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

final readonly class SendResult
{
    public function __construct(public bool $accepted, public ?string $providerId = null, public ?string $error = null) {}

    public static function accepted(?string $providerId): self
    {
        return new self(true, $providerId);
    }

    public static function rejected(string $error): self
    {
        return new self(false, error: mb_substr($error, 0, 300));
    }
}
