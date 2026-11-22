<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

final readonly class ChargeResult
{
    public function __construct(
        public string $status,               // succeeded | failed | pending
        public ?string $gatewayReference = null,
        public ?string $message = null,
        public ?SavedCard $card = null,
        public ?int $amountCents = null,
    ) {}

    public static function pending(?string $message = null): self
    {
        return new self('pending', message: $message);
    }
}
