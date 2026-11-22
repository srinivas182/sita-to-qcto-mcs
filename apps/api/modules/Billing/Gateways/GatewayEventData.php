<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

/** A verified webhook, normalised. */
final readonly class GatewayEventData
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public string $eventId,
        public string $type,                 // payment.succeeded | payment.failed | payment.cancelled | ignored
        public ?string $reference,
        public ?string $gatewayReference,
        public ?int $amountCents,
        public array $payload,
        public ?SavedCard $card = null,
        public ?string $message = null,
    ) {}
}
