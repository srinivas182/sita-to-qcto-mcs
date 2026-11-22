<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Support\Money;
use Modules\Settings\Models\Integration;

/**
 * Peach Payments Hosted Checkout V2 (https://developer.peachpayments.com).
 *  - Access token: POST {dashboard}/api/oauth/token with clientId, clientSecret,
 *    merchantId (cached until it expires).
 *  - Checkout: POST {secure}/v2/checkout (Bearer token, Referer of an allowlisted
 *    domain) with authentication.entityId, merchantTransactionId (8–16 characters),
 *    amount, currency, nonce, shopperResultUrl and notificationUrl → redirectUrl.
 *  - Webhooks (form-encoded) carry checkoutId, merchantTransactionId and result.code.
 *    When signing is on they are checked with HMAC-SHA256 over
 *    "{timestamp}.{id}.{webhook URL}.{raw body}". The outcome is then always
 *    confirmed with GET /v2/checkout/{checkoutId}/status.
 */
final class PeachGateway implements PaymentGateway
{
    /** Result codes that mean "successfully processed" (Peach/OPP convention). */
    private const SUCCESS = '/^(000\.000\.|000\.100\.1|000\.[36])/';

    private const PENDING = '/^(000\.200)/';

    public function __construct(private readonly Integration $integration) {}

    public function driver(): string
    {
        return 'payment.peach';
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        $returnHost = (string) parse_url($request->returnUrl, PHP_URL_SCHEME).'://'.parse_url($request->returnUrl, PHP_URL_HOST);
        $response = Http::withToken($this->token())->withHeaders(['Referer' => $returnHost])
            ->acceptJson()->asJson()->timeout(20)->connectTimeout(5)
            ->post($this->secureHost().'/v2/checkout', [
                'authentication' => ['entityId' => (string) $this->integration->value('entity_id')],
                'merchantTransactionId' => $request->reference,
                'amount' => (float) Money::decimal($request->amountCents),
                'currency' => 'ZAR',
                'nonce' => (string) Str::uuid(),
                'shopperResultUrl' => $request->returnUrl,
                'notificationUrl' => $request->notifyUrl,
                'cancelUrl' => $request->cancelUrl,
                'customer' => array_filter(['email' => $request->email, 'companyName' => $request->customerName]),
            ]);

        $body = (array) $response->json();
        if (! $response->successful() || ! isset($body['redirectUrl'])) {
            throw new GatewayError('Peach Payments did not start the payment: '.($body['result']['description'] ?? $body['message'] ?? $response->status()));
        }

        return new CheckoutSession((string) $body['redirectUrl'], gatewayReference: (string) ($body['checkoutId'] ?? ''));
    }

    public function parseWebhook(Request $request): ?GatewayEventData
    {
        $secret = (string) $this->integration->value('webhook_secret', '');

        if ($secret !== '') {
            $signed = $request->header('x-webhook-timestamp').'.'.$request->header('x-webhook-id').'.'.Gateways::webhookUrl($this->driver()).'.'.$request->getContent();
            if (! hash_equals(hash_hmac('sha256', $signed, $secret), (string) $request->header('x-webhook-signature', ''))) {
                return null;
            }
        }

        /** @var array<string, mixed> $fields */
        $fields = $request->all();
        $checkoutId = (string) ($fields['checkoutId'] ?? '');
        if ($checkoutId === '') {
            return null;
        }

        // Never trust the posted outcome alone: ask Peach.
        $status = $this->status($checkoutId);
        $code = (string) ($status['result']['code'] ?? data_get($fields, 'result.code', ''));

        return new GatewayEventData(
            eventId: $checkoutId.':'.$code,
            type: match (true) {
                preg_match(self::SUCCESS, $code) === 1 => 'payment.succeeded',
                preg_match(self::PENDING, $code) === 1, $code === '' => 'ignored',
                str_starts_with($code, '100.396.10') => 'payment.cancelled',
                default => 'payment.failed',
            },
            reference: (string) ($status['merchantTransactionId'] ?? $fields['merchantTransactionId'] ?? ''),
            gatewayReference: $checkoutId,
            amountCents: isset($status['amount']) ? (int) round(((float) $status['amount']) * 100) : null,
            payload: $fields,
            message: (string) ($status['result']['description'] ?? ''),
        );
    }

    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult
    {
        return new ChargeResult('failed', message: 'Saved-card renewals with Peach Payments need a recurring contract; pay this invoice from Billing.');
    }

    public function lookup(Payment $payment): ChargeResult
    {
        if ($payment->gateway_reference === null) {
            return ChargeResult::pending();
        }

        $code = (string) ($this->status($payment->gateway_reference)['result']['code'] ?? '');

        return match (true) {
            preg_match(self::SUCCESS, $code) === 1 => new ChargeResult('succeeded', $payment->gateway_reference),
            $code === '' || preg_match(self::PENDING, $code) === 1 => ChargeResult::pending(),
            default => new ChargeResult('failed', $payment->gateway_reference, 'Peach Payments: '.$code),
        };
    }

    public function ping(): void
    {
        $this->token(fresh: true);
    }

    /** @return array<string, mixed> */
    private function status(string $checkoutId): array
    {
        $response = Http::withToken($this->token())->acceptJson()->timeout(15)
            ->get($this->secureHost().'/v2/checkout/'.rawurlencode($checkoutId).'/status', ['authentication.entityId' => (string) $this->integration->value('entity_id')]);

        return $response->successful() ? (array) $response->json() : [];
    }

    private function token(bool $fresh = false): string
    {
        $key = 'peach-token:'.$this->integration->id.':'.$this->integration->updated_at->timestamp;
        if ($fresh) {
            Cache::forget($key);
        }

        return (string) Cache::remember($key, 3000, function (): string {
            $response = Http::acceptJson()->asJson()->timeout(15)->connectTimeout(5)->post($this->authHost().'/api/oauth/token', [
                'clientId' => (string) $this->integration->value('client_id'),
                'clientSecret' => (string) $this->integration->value('client_secret'),
                'merchantId' => (string) $this->integration->value('merchant_id'),
            ]);

            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token)) {
                throw new GatewayError('Peach Payments refused the client ID, secret or merchant ID.');
            }

            return $token;
        });
    }

    private function authHost(): string
    {
        return $this->integration->isSandbox() ? 'https://sandbox-dashboard.peachpayments.com' : 'https://dashboard.peachpayments.com';
    }

    private function secureHost(): string
    {
        return $this->integration->isSandbox() ? 'https://testsecure.peachpayments.com' : 'https://secure.peachpayments.com';
    }
}
