<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

/** A reusable card token returned by a gateway, with display details only. */
final readonly class SavedCard
{
    public function __construct(
        public string $token,
        public ?string $customerRef = null,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expMonth = null,
        public ?int $expYear = null,
        public ?string $fingerprint = null,
    ) {}
}
