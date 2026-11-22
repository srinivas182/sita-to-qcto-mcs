<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Support\Money;
use Modules\Settings\Models\Integration;

/**
 * Ozow Instant EFT (https://hub.ozow.com).
 *  - POST https://api.ozow.com/PostPaymentRequest with the ApiKey header and a
 *    SHA512 HashCheck → paymentRequestId and url.
 *  - HashCheck: SiteCode, CountryCode, CurrencyCode, Amount, TransactionReference,
 *    BankReference, CancelUrl, ErrorUrl, SuccessUrl, NotifyUrl, IsTest, then the
 *    private key; lower-cased; SHA512 hex.
 *  - Notification: fields 1–13 in order plus the private key, lower-cased, SHA512;
 *    compared without leading zeros. Status Complete means paid.
 * One-off payments only.
 */
final class OzowGateway implements PaymentGateway
{
    public function __construct(private readonly Integration $integration) {}

    public function driver(): string
    {
        return 'payment.ozow';
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        $fields = [
            'SiteCode' => (string) $this->integration->value('site_code'),
            'CountryCode' => 'ZA',
            'CurrencyCode' => 'ZAR',
            'Amount' => Money::decimal($request->amountCents),
            'TransactionReference' => $request->reference,
            'BankReference' => mb_substr($request->reference, 0, 20),
            'CancelUrl' => $request->cancelUrl,
            'ErrorUrl' => $request->cancelUrl,
            'SuccessUrl' => $request->returnUrl,
            'NotifyUrl' => $request->notifyUrl,
            'IsTest' => $this->integration->isSandbox() ? 'true' : 'false',
        ];
        $fields['HashCheck'] = self::hash(array_values($fields), $this->privateKey());

        $response = Http::withHeaders(['ApiKey' => (string) $this->integration->value('api_key')])
            ->acceptJson()->asJson()->timeout(20)->connectTimeout(5)
            ->post('https://api.ozow.com/PostPaymentRequest', $fields);

        $body = (array) $response->json();
        if (! $response->successful() || empty($body['url'])) {
            throw new GatewayError('Ozow did not start the payment: '.($body['errorMessage'] ?? $response->status()));
        }

        return new CheckoutSession((string) $body['url'], gatewayReference: (string) ($body['paymentRequestId'] ?? ''));
    }

    public function parseWebhook(Request $request): ?GatewayEventData
    {
        /** @var array<string, string> $posted */
        $posted = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $request->all());
        $order = ['SiteCode', 'TransactionId', 'TransactionReference', 'Amount', 'Status', 'Optional1', 'Optional2', 'Optional3', 'Optional4', 'Optional5', 'CurrencyCode', 'IsTest', 'StatusMessage'];
        $expected = self::hash(array_map(fn (string $k): string => $posted[$k] ?? '', $order), $this->privateKey());
        $given = $posted['Hash'] ?? '';

        if ($given === '' || ! hash_equals(ltrim($expected, '0'), ltrim(strtolower($given), '0'))) {
            return null;
        }

        if (($posted['SiteCode'] ?? '') !== (string) $this->integration->value('site_code')) {
            return null;
        }

        return new GatewayEventData(
            eventId: ($posted['TransactionId'] ?? '').':'.($posted['Status'] ?? ''),
            type: match ($posted['Status'] ?? '') {
                'Complete' => 'payment.succeeded',
                'Cancelled', 'Abandoned' => 'payment.cancelled',
                'Error' => 'payment.failed',
                default => 'ignored',          // Pending, PendingInvestigation
            },
            reference: $posted['TransactionReference'] ?? null,
            gatewayReference: $posted['TransactionId'] ?? null,
            amountCents: isset($posted['Amount']) ? (int) round(((float) $posted['Amount']) * 100) : null,
            payload: $posted,
            message: $posted['StatusMessage'] ?? null,
        );
    }

    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult
    {
        return new ChargeResult('failed', message: 'Ozow takes one-off bank payments only.');
    }

    public function lookup(Payment $payment): ChargeResult
    {
        return ChargeResult::pending();
    }

    /** @param  list<string>  $values */
    public static function hash(array $values, string $privateKey): string
    {
        return hash('sha512', strtolower(implode('', $values).$privateKey));
    }

    private function privateKey(): string
    {
        return (string) $this->integration->value('private_key');
    }
}
