<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Support\Money;
use Modules\Settings\Models\Integration;

/**
 * PayFast (https://developers.payfast.co.za).
 *  - Checkout: an HTML form posted to /eng/process, signed with MD5 over the fields
 *    in order plus the passphrase. `subscription_type=2` asks PayFast to keep the
 *    card as a token for later ad hoc charges.
 *  - ITN (webhook): form POST. We check the signature, then ask PayFast to confirm
 *    it with /eng/query/validate. The amount is checked against our payment by the
 *    caller.
 *  - Ad hoc charge: POST api.payfast.co.za/subscriptions/{token}/adhoc, signed with
 *    MD5 over all parameters and headers sorted by name plus the passphrase.
 */
final class PayFastGateway implements PaymentGateway
{
    public function __construct(private readonly Integration $integration) {}

    public function driver(): string
    {
        return 'payment.payfast';
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        // The order of fields is part of the signature.
        $fields = array_filter([
            'merchant_id' => (string) $this->integration->value('merchant_id'),
            'merchant_key' => (string) $this->integration->value('merchant_key'),
            'return_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'notify_url' => $request->notifyUrl,
            'name_first' => $request->customerName,
            'email_address' => $request->email,
            'm_payment_id' => $request->reference,
            'amount' => Money::decimal($request->amountCents),
            'item_name' => mb_substr($request->description, 0, 100),
            'payment_method' => $request->method === 'instant_eft' ? 'eft' : null,
            'subscription_type' => $request->saveMethod ? '2' : null,
        ], fn (?string $v): bool => $v !== null && $v !== '');

        $fields['signature'] = self::formSignature($fields, $this->passphrase());

        return new CheckoutSession($this->host().'/eng/process', 'POST', $fields);
    }

    public function parseWebhook(Request $request): ?GatewayEventData
    {
        /** @var array<string, string> $posted */
        $posted = array_map('strval', $request->request->all());
        $signature = $posted['signature'] ?? '';
        unset($posted['signature']);

        if ($signature === '' || ! hash_equals(self::formSignature($posted, $this->passphrase()), $signature)) {
            return null;
        }

        if (! $this->confirmWithPayFast($request->getContent())) {
            return null;
        }

        $status = $posted['payment_status'] ?? '';
        $type = match ($status) {
            'COMPLETE' => 'payment.succeeded',
            'FAILED' => 'payment.failed',
            'CANCELLED' => 'payment.cancelled',
            default => 'ignored',
        };

        $token = $posted['token'] ?? '';

        return new GatewayEventData(
            eventId: ($posted['pf_payment_id'] ?? sha1($request->getContent())).':'.$status,
            type: $type,
            reference: $posted['m_payment_id'] ?? null,
            gatewayReference: $posted['pf_payment_id'] ?? null,
            amountCents: isset($posted['amount_gross']) ? (int) round(((float) $posted['amount_gross']) * 100) : null,
            payload: $posted,
            card: $token !== '' ? new SavedCard($token, customerRef: $posted['email_address'] ?? null, brand: 'card') : null,
        );
    }

    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult
    {
        $body = ['amount' => $payment->amount_cents, 'item_name' => mb_substr($description, 0, 100), 'm_payment_id' => $payment->reference];
        $response = $this->api($body)->post('/subscriptions/'.rawurlencode($method->token).'/adhoc'.$this->testing(), $body);
        $json = (array) $response->json();

        if ($response->successful() && (($json['data']['response'] ?? null) === true || ($json['status'] ?? '') === 'success')) {
            // Settles by ITN.
            return ChargeResult::pending('Charge requested.');
        }

        return new ChargeResult('failed', message: (string) ($json['data']['message'] ?? $json['message'] ?? 'PayFast did not accept the charge.'));
    }

    public function lookup(Payment $payment): ChargeResult
    {
        // PayFast reports outcomes by ITN only.
        return ChargeResult::pending();
    }

    public function ping(): void
    {
        $response = $this->api([])->get('/ping'.$this->testing());

        if (! $response->successful()) {
            throw new GatewayError('PayFast refused the merchant ID or passphrase.');
        }
    }

    /**
     * MD5 over the fields in the given order, URL-encoded with spaces as "+", then the
     * passphrase. Used for the checkout form and to check ITNs.
     *
     * @param  array<string, string>  $fields
     */
    public static function formSignature(array $fields, string $passphrase): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            if ($value === '') {
                continue;
            }
            $pairs[] = $key.'='.urlencode(trim($value));
        }

        if ($passphrase !== '') {
            $pairs[] = 'passphrase='.urlencode(trim($passphrase));
        }

        return md5(implode('&', $pairs));
    }

    /**
     * MD5 over every parameter and header sorted by name, plus the passphrase, for the
     * REST API.
     *
     * @param  array<string, string|int>  $params
     */
    public static function apiSignature(array $params, string $passphrase): string
    {
        $params['passphrase'] = $passphrase;
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key.'='.urlencode(trim((string) $value));
        }

        return md5(implode('&', $pairs));
    }

    private function confirmWithPayFast(string $rawBody): bool
    {
        // Send back everything except the signature, as received.
        $body = (string) preg_replace('/(^|&)signature=[^&]*/', '', $rawBody);
        $response = Http::timeout(15)->withBody(ltrim($body, '&'), 'application/x-www-form-urlencoded')
            ->post($this->host().'/eng/query/validate');

        return trim($response->body()) === 'VALID';
    }

    /** @param  array<string, string|int>  $params */
    private function api(array $params): PendingRequest
    {
        $headers = [
            'merchant-id' => (string) $this->integration->value('merchant_id'),
            'version' => 'v1',
            'timestamp' => now()->format('Y-m-d\TH:i:sP'),
        ];
        $headers['signature'] = self::apiSignature([...$headers, ...$params], $this->passphrase());

        return Http::baseUrl('https://api.payfast.co.za')->withHeaders($headers)->acceptJson()->asForm()->timeout(20)->connectTimeout(5);
    }

    private function testing(): string
    {
        return $this->integration->isSandbox() ? '?testing=true' : '';
    }

    private function host(): string
    {
        return $this->integration->isSandbox() ? 'https://sandbox.payfast.co.za' : 'https://www.payfast.co.za';
    }

    private function passphrase(): string
    {
        return (string) $this->integration->value('passphrase', '');
    }
}
