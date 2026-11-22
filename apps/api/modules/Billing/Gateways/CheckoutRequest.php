<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

final readonly class CheckoutRequest
{
    public function __construct(
        public string $reference,
        public int $amountCents,
        public string $email,
        public string $description,
        public string $returnUrl,
        public string $cancelUrl,
        public string $notifyUrl,
        public bool $saveMethod,
        public string $method = 'card',      // card | instant_eft
        public ?string $customerName = null,
    ) {}
}
