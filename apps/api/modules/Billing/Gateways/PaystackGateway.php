<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Settings\Models\Integration;

/**
 * Paystack (https://paystack.com/docs).
 *  - Checkout: POST /transaction/initialize → authorization_url (hosted page).
 *  - Saved cards: the reusable `authorization` from a successful charge is charged
 *    again with POST /transaction/charge_authorization.
 *  - Webhooks: HMAC-SHA512 of the raw body keyed with the secret key, in
 *    x-paystack-signature.
 * Amounts are in the currency's subunit (cents for ZAR). Test and live keys select
 * the environment; there is no separate sandbox host.
 */
final class PaystackGateway implements PaymentGateway
{
    private const API = 'https://api.paystack.co';

    public function __construct(private readonly Integration $integration) {}

    public function driver(): string
    {
        return 'payment.paystack';
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        $response = $this->http()->post('/transaction/initialize', array_filter([
            'email' => $request->email,
            'amount' => $request->amountCents,
            'currency' => 'ZAR',
            'reference' => $request->reference,
            'callback_url' => $request->returnUrl,
            'channels' => $request->method === 'instant_eft' ? ['bank', 'eft'] : ['card'],
            'metadata' => ['description' => $request->description, 'cancel_action' => $request->cancelUrl],
        ]));

        $data = $this->ok($response->json(), 'Could not start the Paystack payment.');

        return new CheckoutSession((string) $data['authorization_url'], gatewayReference: (string) ($data['access_code'] ?? ''));
    }

    public function parseWebhook(Request $request): ?GatewayEventData
    {
        $signature = (string) $request->header('x-paystack-signature', '');
        $expected = hash_hmac('sha512', $request->getContent(), $this->secret());

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return null;
        }

        /** @var array{event?: string, data?: array<string, mixed>} $body */
        $body = (array) $request->json()->all();
        $event = (string) ($body['event'] ?? '');
        $data = (array) ($body['data'] ?? []);
        $type = match ($event) {
            'charge.success' => 'payment.succeeded',
            'charge.failed' => 'payment.failed',
            default => 'ignored',
        };

        return new GatewayEventData(
            eventId: $event.':'.($data['id'] ?? $data['reference'] ?? sha1($request->getContent())),
            type: $type,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            amountCents: isset($data['amount']) ? (int) $data['amount'] : null,
            payload: $body,
            card: $this->card($data),
            message: isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
        );
    }

    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult
    {
        $response = $this->http()->post('/transaction/charge_authorization', [
            'authorization_code' => $method->token,
            'email' => $method->customer_ref,
            'amount' => $payment->amount_cents,
            'currency' => 'ZAR',
            'reference' => $payment->reference,
            'metadata' => ['description' => $description],
        ]);

        $body = (array) $response->json();
        if (! ($body['status'] ?? false)) {
            return new ChargeResult('failed', message: (string) ($body['message'] ?? 'The card was not charged.'));
        }

        return $this->result((array) $body['data']);
    }

    public function lookup(Payment $payment): ChargeResult
    {
        $body = (array) $this->http()->get('/transaction/verify/'.rawurlencode($payment->reference))->json();

        if (! ($body['status'] ?? false)) {
            return ChargeResult::pending((string) ($body['message'] ?? ''));
        }

        return $this->result((array) $body['data']);
    }

    /** A read-only call that proves the secret key works. */
    public function ping(): void
    {
        $this->ok($this->http()->get('/transaction', ['perPage' => 1])->json(), 'Paystack refused the secret key.');
    }

    /** @param  array<string, mixed>  $data */
    private function result(array $data): ChargeResult
    {
        $status = match ($data['status'] ?? null) {
            'success' => 'succeeded',
            'failed', 'reversed', 'abandoned' => 'failed',
            default => 'pending',
        };

        return new ChargeResult(
            $status,
            isset($data['id']) ? (string) $data['id'] : null,
            isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
            $this->card($data),
            isset($data['amount']) ? (int) $data['amount'] : null,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function card(array $data): ?SavedCard
    {
        $auth = (array) ($data['authorization'] ?? []);

        if (($auth['reusable'] ?? false) !== true || ! isset($auth['authorization_code'])) {
            return null;
        }

        return new SavedCard(
            token: (string) $auth['authorization_code'],
            customerRef: isset($data['customer']['email']) ? (string) $data['customer']['email'] : null,
            brand: isset($auth['card_type']) ? strtolower(trim((string) $auth['card_type'])) : null,
            last4: isset($auth['last4']) ? (string) $auth['last4'] : null,
            expMonth: isset($auth['exp_month']) ? (int) $auth['exp_month'] : null,
            expYear: isset($auth['exp_year']) ? (int) $auth['exp_year'] : null,
            fingerprint: isset($auth['signature']) ? (string) $auth['signature'] : null,
        );
    }

    /**
     * @param  mixed  $body
     * @return array<string, mixed>
     */
    private function ok($body, string $message): array
    {
        if (! is_array($body) || ! ($body['status'] ?? false)) {
            $detail = is_array($body) && isset($body['message']) ? ': '.$body['message'] : '';

            throw new GatewayError($message.$detail);
        }

        return (array) ($body['data'] ?? []);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken($this->secret())->acceptJson()->asJson()->timeout(20)->connectTimeout(5);
    }

    private function secret(): string
    {
        return (string) $this->integration->value('secret_key');
    }
}
