<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

/** A delivery status from a provider, matched by its message ID or our reference. */
final readonly class Receipt
{
    public function __construct(
        public ?string $providerId,
        public string $status,             // sent | delivered | read | failed | undelivered
        public ?string $reference = null,
        public ?string $error = null,
    ) {}
}
