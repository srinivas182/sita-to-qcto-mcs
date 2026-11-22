<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Settings\Models\Integration;

/**
 * Yoco Checkout API (https://developer.yoco.com).
 *  - POST https://payments.yoco.com/api/checkouts with the secret key → id, redirectUrl.
 *  - Webhooks use the Standard Webhooks scheme (webhook-id, webhook-timestamp,
 *    webhook-signature; whsec_ secret). Event type `payment.succeeded`.
 * Test or live is decided by the keys. Card only; no saved cards. Minimum R2.
 */
final class YocoGateway implements PaymentGateway
{
    public function __construct(private readonly Integration $integration) {}

    public function driver(): string
    {
        return 'payment.yoco';
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        if ($request->amountCents < 200) {
            throw new GatewayError('Yoco accepts payments of R2 or more.');
        }

        $response = Http::withToken((string) $this->integration->value('secret_key'))
            ->withHeaders(['Idempotency-Key' => $request->reference])
            ->acceptJson()->asJson()->timeout(20)->connectTimeout(5)
            ->post('https://payments.yoco.com/api/checkouts', [
                'amount' => $request->amountCents,
                'currency' => 'ZAR',
                'successUrl' => $request->returnUrl,
                'cancelUrl' => $request->cancelUrl,
                'failureUrl' => $request->cancelUrl,
                'metadata' => ['reference' => $request->reference, 'description' => $request->description],
            ]);

        $body = (array) $response->json();
        if (! $response->successful() || ! isset($body['redirectUrl'])) {
            throw new GatewayError('Yoco did not start the payment: '.($body['description'] ?? $body['message'] ?? $response->status()));
        }

        return new CheckoutSession((string) $body['redirectUrl'], gatewayReference: (string) ($body['id'] ?? ''));
    }

    public function parseWebhook(Request $request): ?GatewayEventData
    {
        $valid = StandardWebhook::verify(
            (string) $request->header('webhook-id', ''),
            (string) $request->header('webhook-timestamp', ''),
            (string) $request->header('webhook-signature', ''),
            $request->getContent(),
            (string) $this->integration->value('webhook_secret', ''),
        );

        if (! $valid) {
            return null;
        }

        $body = (array) $request->json()->all();
        $payload = (array) ($body['payload'] ?? []);
        $metadata = (array) ($payload['metadata'] ?? []);

        return new GatewayEventData(
            eventId: (string) $request->header('webhook-id'),
            type: match ($body['type'] ?? '') {
                'payment.succeeded' => 'payment.succeeded',
                'payment.failed' => 'payment.failed',
                default => 'ignored',
            },
            reference: isset($metadata['reference']) ? (string) $metadata['reference'] : null,
            gatewayReference: isset($metadata['checkoutId']) ? (string) $metadata['checkoutId'] : (isset($payload['id']) ? (string) $payload['id'] : null),
            amountCents: isset($payload['amount']) ? (int) $payload['amount'] : null,
            payload: $body,
        );
    }

    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult
    {
        return new ChargeResult('failed', message: 'Yoco does not keep cards for renewals.');
    }

    public function lookup(Payment $payment): ChargeResult
    {
        return ChargeResult::pending();
    }
}
