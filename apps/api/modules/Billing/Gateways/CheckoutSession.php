<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

/** Where to send the customer: a link, or a form to post (PayFast). */
final readonly class CheckoutSession
{
    /** @param  array<string, string>  $fields */
    public function __construct(public string $url, public string $method = 'GET', public array $fields = [], public ?string $gatewayReference = null) {}

    /** @return array{url: string, method: string, fields: object} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'method' => $this->method, 'fields' => (object) $this->fields];
    }
}
