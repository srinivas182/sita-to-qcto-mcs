<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Settings\Models\Integration;

/**
 * iKhokha iK Pay API (https://developer.ikhokha.com).
 *  - POST https://api.ikhokha.com/public-api/v1/api/payment with IK-APPID and IK-SIGN
 *    → paylinkUrl, paylinkID.
 *  - IK-SIGN: HMAC-SHA256 (hex) with the application secret over the escaped string
 *    "{endpoint path}{JSON body}"; backslashes and quotes are escaped with a backslash.
 *  - Callback: POST to callbackUrl with ik-sign over "{callback path}{JSON body}",
 *    status SUCCESS or FAILURE, externalTransactionID = our reference.
 * Cards and Instant EFT through the hosted payment link. One-off payments only.
 */
final class IKhokhaGateway implements PaymentGateway
{
    private const BASE = 'https://api.ikhokha.com';

    private const PATH = '/public-api/v1/api/payment';

    public function __construct(private readonly Integration $integration) {}

    public function driver(): string
    {
        return 'payment.ikhokha';
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        $appId = (string) $this->integration->value('application_id');
        $payload = [
            'entityID' => $appId,
            'externalEntityID' => $appId,
            'amount' => $request->amountCents,
            'currency' => 'ZAR',
            'requesterUrl' => (string) parse_url($request->returnUrl, PHP_URL_SCHEME).'://'.parse_url($request->returnUrl, PHP_URL_HOST),
            'description' => mb_substr($request->description, 0, 100),
            'paymentReference' => $request->reference,
            'mode' => $this->integration->isSandbox() ? 'test' : 'live',
            'externalTransactionID' => $request->reference,
            'urls' => [
                'callbackUrl' => $request->notifyUrl,
                'successPageUrl' => $request->returnUrl,
                'failurePageUrl' => $request->cancelUrl,
                'cancelUrl' => $request->cancelUrl,
            ],
        ];
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = Http::withHeaders(['IK-APPID' => $appId, 'IK-SIGN' => $this->sign(self::PATH, $json)])
            ->acceptJson()->timeout(20)->connectTimeout(5)
            ->withBody($json, 'application/json')
            ->post(self::BASE.self::PATH);

        $body = (array) $response->json();
        if (! $response->successful() || ($body['responseCode'] ?? null) !== '00' || ! isset($body['paylinkUrl'])) {
            throw new GatewayError('iKhokha did not start the payment: '.($body['message'] ?? $response->status()));
        }

        return new CheckoutSession((string) $body['paylinkUrl'], gatewayReference: (string) ($body['paylinkID'] ?? ''));
    }

    public function parseWebhook(Request $request): ?GatewayEventData
    {
        $body = (array) $request->json()->all();
        unset($body['text']);
        $path = (string) parse_url(Gateways::webhookUrl($this->driver()), PHP_URL_PATH);
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! hash_equals($this->sign($path, $json), strtolower((string) $request->header('ik-sign', '')))) {
            return null;
        }

        if ((string) $request->header('ik-appid', '') !== (string) $this->integration->value('application_id')) {
            return null;
        }

        return new GatewayEventData(
            eventId: ($body['paylinkID'] ?? '').':'.($body['status'] ?? ''),
            type: match ($body['status'] ?? '') {
                'SUCCESS' => 'payment.succeeded',
                'FAILURE' => 'payment.failed',
                default => 'ignored',
            },
            reference: isset($body['externalTransactionID']) ? (string) $body['externalTransactionID'] : null,
            gatewayReference: isset($body['paylinkID']) ? (string) $body['paylinkID'] : null,
            amountCents: null,   // not in the callback; the link's amount was fixed by us
            payload: $body,
        );
    }

    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult
    {
        return new ChargeResult('failed', message: 'iKhokha takes one-off payments only.');
    }

    public function lookup(Payment $payment): ChargeResult
    {
        return ChargeResult::pending();
    }

    /** HMAC-SHA256 (hex) of the escaped "{path}{body}", keyed with the application secret. */
    public function sign(string $path, string $body): string
    {
        $escaped = str_replace("\0", '\\0', (string) preg_replace('/[\\\\"\']/', '\\\\$0', $path.$body));

        return hash_hmac('sha256', $escaped, (string) $this->integration->value('application_secret'));
    }
}
