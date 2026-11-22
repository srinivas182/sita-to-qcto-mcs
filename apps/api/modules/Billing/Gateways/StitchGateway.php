<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Support\Money;
use Modules\Settings\Models\Integration;

/**
 * Stitch (https://docs.stitch.money), REST payment requests.
 *  - Client token: POST https://secure.stitch.money/connect/token (client
 *    credentials, scope client_paymentrequest).
 *  - POST https://api.stitch.money/v2/payment-requests → id and interaction.url;
 *    redirect_uri and failure_redirect_uri are appended to the URL.
 *  - Webhooks arrive through Svix (svix-id, svix-timestamp, svix-signature). The
 *    status is always read back with GET /v2/payment-requests/{id}: `completed`
 *    means paid.
 * Pay by bank (with Capitec Pay) and cards. Test or live is decided by the client.
 */
final class StitchGateway implements PaymentGateway
{
    public function __construct(private readonly Integration $integration) {}

    public function driver(): string
    {
        return 'payment.stitch';
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        $merchantId = (string) $this->integration->value('merchant_id', '');
        $eft = [
            'enabled' => true,
            'payerReference' => mb_substr($request->reference, 0, 20),
            'beneficiaryReference' => mb_substr($request->reference, 0, 20),
            'capitecPay' => ['enabled' => true],
        ];
        if ($merchantId === '') {
            $eft['beneficiary'] = [
                'name' => (string) $this->integration->value('beneficiary_name'),
                'bank' => (string) $this->integration->value('beneficiary_bank'),
                'accountNumber' => (string) $this->integration->value('beneficiary_account_number'),
            ];
        }

        $response = Http::withToken($this->token())->acceptJson()->asJson()->timeout(20)->connectTimeout(5)
            ->post('https://api.stitch.money/v2/payment-requests', array_filter([
                'amount' => ['currency' => 'ZAR', 'quantity' => (float) Money::decimal($request->amountCents)],
                'externalReference' => $request->reference,
                'expireAt' => now()->addDay()->toIso8601String(),
                'merchantId' => $merchantId !== '' ? $merchantId : null,
                'payer' => array_filter(['identifier' => $request->email, 'email' => $request->email, 'fullName' => $request->customerName]),
                'paymentMethods' => [
                    'eft' => $eft,
                    'card' => ['enabled' => $request->method === 'card'],
                ],
            ], fn ($v) => $v !== null));

        $body = (array) $response->json();
        $url = data_get($body, 'interaction.url');
        if (! $response->successful() || ! is_string($url)) {
            throw new GatewayError('Stitch did not start the payment: '.($body['message'] ?? $body['error'] ?? $response->status()));
        }

        $url .= (str_contains($url, '?') ? '&' : '?').http_build_query(['redirect_uri' => $request->returnUrl, 'failure_redirect_uri' => $request->cancelUrl]);

        return new CheckoutSession($url, gatewayReference: (string) ($body['id'] ?? ''));
    }

    public function parseWebhook(Request $request): ?GatewayEventData
    {
        $valid = StandardWebhook::verify(
            (string) $request->header('svix-id', ''),
            (string) $request->header('svix-timestamp', ''),
            (string) $request->header('svix-signature', ''),
            $request->getContent(),
            (string) $this->integration->value('webhook_secret', ''),
        );

        if (! $valid) {
            return null;
        }

        $body = (array) $request->json()->all();
        $id = (string) (data_get($body, 'id') ?? data_get($body, 'data.id') ?? data_get($body, 'paymentRequest.id') ?? '');
        if ($id === '') {
            return new GatewayEventData((string) $request->header('svix-id'), 'ignored', null, null, null, $body);
        }

        // Read the outcome back from Stitch rather than trusting the body.
        $status = $this->status($id);

        return new GatewayEventData(
            eventId: (string) $request->header('svix-id'),
            type: match ($status['status'] ?? '') {
                'completed' => 'payment.succeeded',
                'cancelled', 'expired' => 'payment.cancelled',
                default => 'ignored',
            },
            reference: isset($status['externalReference']) ? (string) $status['externalReference'] : null,
            gatewayReference: $id,
            amountCents: isset($status['amount']['quantity']) ? (int) round(((float) $status['amount']['quantity']) * 100) : null,
            payload: $body,
        );
    }

    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult
    {
        return new ChargeResult('failed', message: 'Pay this invoice from Billing with Stitch.');
    }

    public function lookup(Payment $payment): ChargeResult
    {
        if ($payment->gateway_reference === null) {
            return ChargeResult::pending();
        }

        return match ($this->status($payment->gateway_reference)['status'] ?? '') {
            'completed' => new ChargeResult('succeeded', $payment->gateway_reference),
            'cancelled', 'expired' => new ChargeResult('cancelled', $payment->gateway_reference, 'The payment request ended without payment.'),
            default => ChargeResult::pending(),
        };
    }

    public function ping(): void
    {
        $this->token(fresh: true);
    }

    /** @return array<string, mixed> */
    private function status(string $id): array
    {
        $response = Http::withToken($this->token())->acceptJson()->timeout(15)->get('https://api.stitch.money/v2/payment-requests/'.rawurlencode($id));

        return $response->successful() ? (array) $response->json() : [];
    }

    private function token(bool $fresh = false): string
    {
        $key = 'stitch-token:'.$this->integration->id.':'.$this->integration->updated_at->timestamp;
        if ($fresh) {
            Cache::forget($key);
        }

        return (string) Cache::remember($key, 600, function (): string {
            $response = Http::asForm()->acceptJson()->timeout(15)->connectTimeout(5)->post('https://secure.stitch.money/connect/token', [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $this->integration->value('client_id'),
                'client_secret' => (string) $this->integration->value('client_secret'),
                'scope' => 'client_paymentrequest',
                'audience' => 'https://secure.stitch.money/connect/token',
            ]);

            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token)) {
                throw new GatewayError('Stitch refused the client ID or secret.');
            }

            return $token;
        });
    }
}
