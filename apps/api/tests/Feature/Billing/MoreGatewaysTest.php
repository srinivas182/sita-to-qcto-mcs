<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Gateways\Gateways;
use Modules\Billing\Gateways\IKhokhaGateway;
use Modules\Billing\Gateways\OzowGateway;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;

beforeEach(function (): void {
    Notification::fake();
    config(['billing.webhook_base' => 'https://skills.test']);
    companyReady();
    $this->tym = makeProvider('tym');
    signInOwner($this, $this->tym);
    $this->base = 'https://tym.skills.test/v1';
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])->assertCreated();
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    Artisan::call('billing:run');
    $this->invoice = asTenant($this->tym, fn () => Invoice::query()->sole());
});

function startPayment(object $test, string $driver, string $method = 'card'): array
{
    return $test->postJson($test->base."/invoices/{$test->invoice->id}/pay", [
        'driver' => $driver, 'method' => $method, 'return_url' => 'https://tym.skills.test/billing',
    ])->assertCreated()->json();
}

function invoicePaid(object $test): bool
{
    return asTenant($test->tym, fn () => Invoice::query()->sole()->status) === 'paid';
}

/** Standard Webhooks / Svix signature for tests. */
function standardSignature(string $id, int $ts, string $body, string $secret): string
{
    return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", (string) base64_decode(substr($secret, 6)), true));
}

it('pays by Ozow Instant EFT with a checked hash both ways', function (): void {
    enableGateway('payment.ozow', ['site_code' => 'TSTSTE0001', 'api_key' => 'apikey', 'private_key' => 'privkey']);
    Http::fake(['api.ozow.com/PostPaymentRequest' => Http::response(['paymentRequestId' => 'req-1', 'url' => 'https://pay.ozow.com/req-1/Secure'])]);

    $start = startPayment($this, 'payment.ozow', 'instant_eft');
    expect($start['checkout']['url'])->toBe('https://pay.ozow.com/req-1/Secure');
    $reference = $start['payment']['reference'];
    expect($reference)->toMatch('/^P[A-Z0-9]{15}$/');

    Http::assertSent(function (HttpRequest $r) use ($reference) {
        $order = ['SiteCode', 'CountryCode', 'CurrencyCode', 'Amount', 'TransactionReference', 'BankReference', 'CancelUrl', 'ErrorUrl', 'SuccessUrl', 'NotifyUrl', 'IsTest'];

        return $r->hasHeader('ApiKey', 'apikey') && $r['TransactionReference'] === $reference && $r['IsTest'] === 'true'
            && $r['NotifyUrl'] === 'https://skills.test/v1/webhooks/payments/payment.ozow'
            && $r['HashCheck'] === hash('sha512', strtolower(collect($order)->map(fn ($k) => $r[$k])->implode('').'privkey'));
    });

    $amount = number_format($this->invoice->total_cents / 100, 2, '.', '');
    $note = ['SiteCode' => 'TSTSTE0001', 'TransactionId' => 'oz-9', 'TransactionReference' => $reference, 'Amount' => $amount, 'Status' => 'Complete',
        'Optional1' => '', 'Optional2' => '', 'Optional3' => '', 'Optional4' => '', 'Optional5' => '', 'CurrencyCode' => 'ZAR', 'IsTest' => 'true', 'StatusMessage' => 'Paid'];
    $hash = OzowGateway::hash(array_values($note), 'privkey');

    $this->post('https://skills.test/v1/webhooks/payments/payment.ozow', [...$note, 'Hash' => str_repeat('0', 0).'bad'])->assertStatus(401);
    $this->post('https://skills.test/v1/webhooks/payments/payment.ozow', [...$note, 'Hash' => $hash])->assertOk()->assertSee('succeeded');
    expect(invoicePaid($this))->toBeTrue();
});

it('pays by Yoco with Standard Webhooks signatures and refuses replays', function (): void {
    $secret = 'whsec_'.base64_encode('yoco-secret-bytes-123');
    enableGateway('payment.yoco', ['public_key' => 'pk_test', 'secret_key' => 'sk_test_yoco', 'webhook_secret' => $secret]);
    Http::fake(['payments.yoco.com/api/checkouts' => Http::response(['id' => 'ch_1', 'redirectUrl' => 'https://c.yoco.com/checkout/ch_1'])]);

    $reference = startPayment($this, 'payment.yoco')['payment']['reference'];
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_yoco') && $r['amount'] === $this->invoice->total_cents && $r['metadata']['reference'] === $reference);

    $body = (string) json_encode(['type' => 'payment.succeeded', 'payload' => ['id' => 'p_1', 'amount' => $this->invoice->total_cents, 'metadata' => ['reference' => $reference, 'checkoutId' => 'ch_1']]]);
    $send = fn (int $ts, ?string $sig = null) => $this->call('POST', 'https://skills.test/v1/webhooks/payments/payment.yoco', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_ID' => 'msg_1', 'HTTP_WEBHOOK_TIMESTAMP' => (string) $ts,
        'HTTP_WEBHOOK_SIGNATURE' => $sig ?? standardSignature('msg_1', $ts, $body, $secret),
    ], $body);

    $send(time() - 3600)->assertStatus(401);             // too old
    $send(time(), 'v1,forged')->assertStatus(401);
    $send(time())->assertOk()->assertSee('succeeded');
    expect(invoicePaid($this))->toBeTrue();
});

it('pays by Peach Payments and confirms the result with Peach', function (): void {
    enableGateway('payment.peach', ['entity_id' => '8ac7a4c', 'client_id' => 'cid', 'client_secret' => 'csecret', 'merchant_id' => 'mid', 'webhook_secret' => 'peach-shared']);
    Http::fake([
        'sandbox-dashboard.peachpayments.com/api/oauth/token' => Http::response(['access_token' => 'jwt-1', 'expires_in' => 3600]),
        'testsecure.peachpayments.com/v2/checkout' => Http::response(['checkoutId' => 'co_1', 'redirectUrl' => 'https://testsecure.peachpayments.com/checkout/co_1']),
    ]);

    $reference = startPayment($this, 'payment.peach')['payment']['reference'];
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout') && $r->hasHeader('Authorization', 'Bearer jwt-1')
        && $r->hasHeader('Referer', 'https://tym.skills.test') && $r['merchantTransactionId'] === $reference && $r['authentication']['entityId'] === '8ac7a4c');

    // Peach's status endpoint, which confirms every webhook.
    Http::fake([
        'sandbox-dashboard.peachpayments.com/*' => Http::response(['access_token' => 'jwt-1']),
        'testsecure.peachpayments.com/v2/checkout/co_1/status*' => Http::response(['result' => ['code' => '000.100.110'], 'amount' => number_format($this->invoice->total_cents / 100, 2, '.', ''), 'merchantTransactionId' => $reference]),
    ]);

    $raw = http_build_query(['checkoutId' => 'co_1', 'merchantTransactionId' => $reference, 'result.code' => '000.100.110']);
    $ts = (string) time();
    $sign = fn (string $secret) => hash_hmac('sha256', "{$ts}.wh_1.https://skills.test/v1/webhooks/payments/payment.peach.{$raw}", $secret);
    // A real form post arrives parsed as well as raw.
    parse_str($raw, $parsed);
    $send = fn (string $sig) => $this->call('POST', 'https://skills.test/v1/webhooks/payments/payment.peach', $parsed, [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_ID' => 'wh_1', 'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
    ], $raw);

    $send($sign('wrong'))->assertStatus(401);
    $send($sign('peach-shared'))->assertOk()->assertSee('succeeded');
    expect(invoicePaid($this))->toBeTrue();
});

it('pays by Stitch, reading the outcome back from Stitch', function (): void {
    $secret = 'whsec_'.base64_encode('stitch-secret');
    enableGateway('payment.stitch', ['client_id' => 'test-client', 'client_secret' => 'sec', 'webhook_secret' => $secret, 'beneficiary_name' => 'TYM MCS JV', 'beneficiary_bank' => 'fnb', 'beneficiary_account_number' => '62000000000']);
    Http::fake([
        'secure.stitch.money/connect/token' => Http::response(['access_token' => 'st-tok', 'expires_in' => 3600]),
        'api.stitch.money/v2/payment-requests' => Http::response(['id' => 'pr_1', 'status' => 'pending', 'interaction' => ['type' => 'redirect', 'url' => 'https://secure.stitch.money/connect/payment-request/pr_1']], 201),
    ]);

    $start = startPayment($this, 'payment.stitch', 'instant_eft');
    $reference = $start['payment']['reference'];
    expect($start['checkout']['url'])->toStartWith('https://secure.stitch.money/connect/payment-request/pr_1?redirect_uri=');
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/payment-requests') && $r['externalReference'] === $reference
        && $r['paymentMethods']['eft']['beneficiary']['bank'] === 'fnb' && $r['amount']['currency'] === 'ZAR');

    Http::fake([
        'secure.stitch.money/connect/token' => Http::response(['access_token' => 'st-tok']),
        'api.stitch.money/v2/payment-requests/pr_1' => Http::response(['id' => 'pr_1', 'status' => 'completed', 'externalReference' => $reference, 'amount' => ['currency' => 'ZAR', 'quantity' => $this->invoice->total_cents / 100]]),
    ]);

    $body = (string) json_encode(['id' => 'pr_1', 'status' => 'completed']);
    $ts = time();
    $this->call('POST', 'https://skills.test/v1/webhooks/payments/payment.stitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => 'msg_s1', 'HTTP_SVIX_TIMESTAMP' => (string) $ts, 'HTTP_SVIX_SIGNATURE' => standardSignature('msg_s1', $ts, $body, $secret),
    ], $body)->assertOk()->assertSee('succeeded');
    expect(invoicePaid($this))->toBeTrue();

    // The return page asks Stitch too.
    $payment = asTenant($this->tym, fn () => Payment::query()->sole());
    $this->getJson($this->base."/payments/{$payment->reference}")->assertJsonPath('data.status', 'succeeded');
});

it('pays by iKhokha with signed requests and callbacks', function (): void {
    $integration = enableGateway('payment.ikhokha', ['application_id' => 'APP123', 'application_secret' => 'ik-secret']);
    Http::fake(['api.ikhokha.com/public-api/v1/api/payment' => Http::response(['responseCode' => '00', 'paylinkUrl' => 'https://securepay.ikhokha.red/abc', 'paylinkID' => 'pl_1'])]);

    $reference = startPayment($this, 'payment.ikhokha')['payment']['reference'];
    $gateway = new IKhokhaGateway($integration);
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('IK-APPID', 'APP123') && $r['externalTransactionID'] === $reference && $r['amount'] === $this->invoice->total_cents
        && $r->header('IK-SIGN')[0] === $gateway->sign('/public-api/v1/api/payment', $r->body()));

    $body = (string) json_encode(['paylinkID' => 'pl_1', 'status' => 'SUCCESS', 'externalTransactionID' => $reference, 'responseCode' => '00']);
    $send = fn (string $sig, string $app = 'APP123') => $this->call('POST', 'https://skills.test/v1/webhooks/payments/payment.ikhokha', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_IK_APPID' => $app, 'HTTP_IK_SIGN' => $sig,
    ], $body);
    $good = $gateway->sign((string) parse_url(Gateways::webhookUrl('payment.ikhokha'), PHP_URL_PATH), $body);

    $send('deadbeef')->assertStatus(401);
    $send($good, 'OTHER')->assertStatus(401);
    $send($good)->assertOk()->assertSee('succeeded');
    expect(invoicePaid($this))->toBeTrue();
});

it('escapes the iKhokha signing string as documented', function (): void {
    $integration = enableGateway('payment.ikhokha', ['application_id' => 'A', 'application_secret' => 's']);
    $expected = hash_hmac('sha256', '/p{\\"a\\":\\"it\\\'s\\"}', 's');
    expect((new IKhokhaGateway($integration))->sign('/p', '{"a":"it\'s"}'))->toBe($expected);
});

it('offers only gateways that can take this kind of payment', function (): void {
    enableGateway('payment.ozow', ['site_code' => 'S', 'api_key' => 'a', 'private_key' => 'p']);
    enableGateway('payment.yoco', ['public_key' => 'pk', 'secret_key' => 'sk', 'webhook_secret' => 'whsec_eA==']);
    $gateways = collect($this->getJson($this->base.'/billing')->json('gateways'))->keyBy('driver');

    expect($gateways['payment.ozow']['capabilities'])->toBe(['instant_eft'])
        ->and($gateways['payment.yoco']['capabilities'])->toBe(['card']);
});
