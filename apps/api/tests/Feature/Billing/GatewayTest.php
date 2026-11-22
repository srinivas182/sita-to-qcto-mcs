<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Gateways\PayFastGateway;
use Modules\Billing\Models\GatewayEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\PaymentReceived;

beforeEach(function (): void {
    Notification::fake();
    companyReady();
    $this->tym = makeProvider('tym');
    signInOwner($this, $this->tym);
    $this->base = 'https://tym.skills.test/v1';
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])->assertCreated();
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    Artisan::call('billing:run');
    $this->invoice = asTenant($this->tym, fn () => Invoice::query()->sole());
});

function paystackWebhook(object $test, array $body, string $secret = 'sk_test_secret'): TestResponse
{
    $raw = json_encode($body);

    return $test->call('POST', 'https://skills.test/v1/webhooks/payments/payment.paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', (string) $raw, $secret),
    ], $raw);
}

it('pays with Paystack, saves the card and renews automatically', function (): void {
    enableGateway('payment.paystack', ['public_key' => 'pk_test', 'secret_key' => 'sk_test_secret']);
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc']]),
        'api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success', 'id' => 99, 'amount' => 186760 - 24360 + 24360, 'gateway_response' => 'Approved']]),
    ]);

    $this->getJson($this->base.'/billing')->assertJsonPath('gateways.0.driver', 'payment.paystack');
    $res = $this->postJson($this->base."/invoices/{$this->invoice->id}/pay", [
        'driver' => 'payment.paystack', 'method' => 'card', 'save_card' => true, 'return_url' => 'https://tym.skills.test/billing',
    ])->assertCreated()->assertJsonPath('checkout.url', 'https://checkout.paystack.com/abc')->assertJsonPath('checkout.method', 'GET');
    $reference = $res->json('payment.reference');

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transaction/initialize')
        && $r['amount'] === $this->invoice->total_cents && $r['currency'] === 'ZAR' && $r['reference'] === $reference
        && $r->hasHeader('Authorization', 'Bearer sk_test_secret'));

    // A forged webhook is refused.
    paystackWebhook($this, ['event' => 'charge.success', 'data' => ['reference' => $reference]], 'wrong')->assertStatus(401);

    $event = ['event' => 'charge.success', 'data' => [
        'id' => 4242, 'reference' => $reference, 'amount' => $this->invoice->total_cents, 'status' => 'success', 'gateway_response' => 'Approved',
        'customer' => ['email' => 'finance@tym.test'],
        'authorization' => ['authorization_code' => 'AUTH_xyz', 'card_type' => 'visa ', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030', 'reusable' => true, 'signature' => 'SIG_1'],
    ]];
    paystackWebhook($this, $event)->assertOk()->assertSee('succeeded');
    paystackWebhook($this, $event)->assertOk()->assertSee('duplicate');

    $this->getJson($this->base."/payments/{$reference}")->assertJsonPath('data.status', 'succeeded');
    $billing = $this->getJson($this->base.'/billing')->json();
    expect($billing['payment_method'])->toMatchArray(['brand' => 'visa', 'last4' => '4081', 'exp_year' => 2030])
        ->and($billing['subscription']['collection'])->toBe('auto')
        ->and($billing['open_invoices'])->toBe([]);
    Notification::assertSentOnDemand(PaymentReceived::class);
    expect(asTenant($this->tym, fn () => PaymentMethod::query()->sole()->getRawOriginal('token')))->not->toContain('AUTH_xyz');

    // Renewal charges the saved card.
    asTenant($this->tym, fn () => Subscription::query()->update(['current_period_end' => now()->subMinute()]));
    Artisan::call('billing:run');
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transaction/charge_authorization') && $r['authorization_code'] === 'AUTH_xyz' && $r['email'] === 'finance@tym.test');
    expect(asTenant($this->tym, fn () => Invoice::query()->where('status', 'paid')->count()))->toBe(2);
});

it('schedules retries when a saved card is declined', function (): void {
    enableGateway('payment.paystack', ['public_key' => 'pk_test', 'secret_key' => 'sk_test_secret']);
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient Funds']])]);

    asTenant($this->tym, function (): void {
        $method = PaymentMethod::query()->create(['tenant_id' => $this->tym->id, 'driver' => 'payment.paystack', 'token' => 'AUTH_1', 'customer_ref' => 'finance@tym.test', 'brand' => 'visa', 'last4' => '1111']);
        Subscription::query()->update(['payment_method_id' => $method->id, 'collection' => 'auto']);
        Invoice::query()->update(['status' => 'paid']);
        Subscription::query()->update(['current_period_end' => now()->subMinute()]);
    });
    Artisan::call('billing:run');

    $invoice = asTenant($this->tym, fn () => Invoice::query()->where('status', 'open')->sole());
    expect($invoice->attempts)->toBe(1)
        ->and($invoice->next_attempt_at?->toDateString())->toBe($invoice->due_at->copy()->addDay()->toDateString())
        ->and(asTenant($this->tym, fn () => Payment::query()->where('status', 'failed')->value('failure_message')))->toBe('Insufficient Funds');

    // The retry is due: charged again.
    asTenant($this->tym, fn () => Invoice::query()->whereKey($invoice->id)->update(['next_attempt_at' => now()->subMinute()]));
    Artisan::call('billing:run');
    expect(asTenant($this->tym, fn () => Invoice::query()->findOrFail($invoice->id)->attempts))->toBe(2);
});

it('builds a signed PayFast checkout and accepts a confirmed ITN', function (): void {
    enableGateway('payment.payfast', ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn']);
    Http::fake(['sandbox.payfast.co.za/eng/query/validate' => Http::response('VALID')]);

    $checkout = $this->postJson($this->base."/invoices/{$this->invoice->id}/pay", [
        'driver' => 'payment.payfast', 'method' => 'card', 'save_card' => true, 'return_url' => 'https://tym.skills.test/billing',
    ])->assertCreated()->assertJsonPath('checkout.method', 'POST')->assertJsonPath('checkout.url', 'https://sandbox.payfast.co.za/eng/process')->json('checkout');

    $fields = $checkout['fields'];
    $signature = $fields['signature'];
    unset($fields['signature']);
    expect($fields['subscription_type'])->toBe('2')
        ->and($fields['amount'])->toBe(number_format($this->invoice->total_cents / 100, 2, '.', ''))
        ->and($signature)->toBe(md5(collect($fields)->map(fn ($v, $k) => $k.'='.urlencode($v))->implode('&').'&passphrase=jt7NOE43FZPn'));

    $itn = [
        'm_payment_id' => $fields['m_payment_id'], 'pf_payment_id' => '1089250', 'payment_status' => 'COMPLETE',
        'item_name' => $fields['item_name'], 'amount_gross' => $fields['amount'], 'amount_fee' => '-10.00',
        'amount_net' => '1857.60', 'email_address' => 'finance@tym.test', 'merchant_id' => '10000100', 'token' => 'dc0521d3-55fe-269b-fa00-b647310d760f',
    ];
    $itn['signature'] = PayFastGateway::formSignature($itn, 'jt7NOE43FZPn');

    $this->post('https://skills.test/v1/webhooks/payments/payment.payfast', [...$itn, 'signature' => 'bad'])->assertStatus(401);
    $this->post('https://skills.test/v1/webhooks/payments/payment.payfast', $itn)->assertOk()->assertSee('succeeded');

    expect(asTenant($this->tym, fn () => Invoice::query()->sole()->status))->toBe('paid')
        ->and(asTenant($this->tym, fn () => PaymentMethod::query()->sole()->driver))->toBe('payment.payfast')
        ->and(asPlatform(fn () => GatewayEvent::query()->count()))->toBe(1);
});

it('flags a webhook whose amount differs and does not mark the invoice paid', function (): void {
    enableGateway('payment.paystack', ['public_key' => 'pk_test', 'secret_key' => 'sk_test_secret']);
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
    $reference = $this->postJson($this->base."/invoices/{$this->invoice->id}/pay", [
        'driver' => 'payment.paystack', 'method' => 'card', 'return_url' => 'https://tym.skills.test/billing',
    ])->json('payment.reference');

    paystackWebhook($this, ['event' => 'charge.success', 'data' => ['id' => 1, 'reference' => $reference, 'amount' => 100, 'status' => 'success']])
        ->assertOk()->assertSee('amount_mismatch');
    expect(asTenant($this->tym, fn () => Invoice::query()->sole()->status))->toBe('open');
});

it('refuses gateways that are off and return addresses on other sites', function (): void {
    $this->postJson($this->base."/invoices/{$this->invoice->id}/pay", ['driver' => 'payment.paystack', 'method' => 'card', 'return_url' => 'https://tym.skills.test/billing'])
        ->assertStatus(422)->assertJsonPath('error.code', 'gateway_unavailable');
    enableGateway('payment.paystack', ['public_key' => 'pk_test', 'secret_key' => 'sk_test_secret']);
    $this->postJson($this->base."/invoices/{$this->invoice->id}/pay", ['driver' => 'payment.paystack', 'method' => 'card', 'return_url' => 'https://evil.example/billing'])
        ->assertStatus(422);
});
