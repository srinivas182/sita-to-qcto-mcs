<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Identity\Models\OtpChallenge;
use Modules\Identity\Support\IdType;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

function messagingProvider(string $driver, array $settings, array $credentials, int $priority = 10): Integration
{
    return asPlatform(fn () => Integration::query()->create([
        'type' => explode('.', $driver)[0], 'driver' => $driver, 'settings' => $settings, 'credentials' => $credentials,
        'enabled' => true, 'priority' => $priority,
    ]));
}

beforeEach(function (): void {
    config(['billing.webhook_base' => 'https://skills.test']);
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->learner = makeMember($this->tym, ['learner'], ['phone_e164' => '+27821234567']);
    asPlatform(function (): void {
        $this->learner->setIdentityNumber(IdType::SaId, '8001015009087');
        $this->learner->save();
    });
    $this->host = 'https://tym.skills.test/v1';
});

it('sends the sign-in PIN as a WhatsApp authentication template and logs it', function (): void {
    messagingProvider('whatsapp.meta', ['phone_number_id' => '1055', 'business_account_id' => '9', 'otp_template' => 'sign_in_code', 'price_cents' => 18], ['access_token' => 'EAAtoken', 'app_secret' => 'appsecret']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);

    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '082 123 4567'])->assertStatus(202);

    $code = null;
    Http::assertSent(function (HttpRequest $r) use (&$code) {
        $components = $r['template']['components'] ?? [];
        $code = $components[0]['parameters'][0]['text'] ?? null;

        return $r->url() === 'https://graph.facebook.com/v21.0/1055/messages' && $r->hasHeader('Authorization', 'Bearer EAAtoken')
            && $r['to'] === '27821234567' && $r['template']['name'] === 'sign_in_code'
            && $components[1]['sub_type'] === 'url' && $components[1]['parameters'][0]['text'] === $code;
    });
    expect($code)->toMatch('/^\d{6}$/');

    // The code is only kept hashed; the log never holds it or the full number.
    $message = asTenant($this->tym, fn () => Message::query()->sole());
    expect($message->only(['channel', 'driver', 'purpose', 'status', 'provider_message_id', 'cost_cents', 'destination_masked']))
        ->toBe(['channel' => 'whatsapp', 'driver' => 'whatsapp.meta', 'purpose' => 'otp', 'status' => 'sent', 'provider_message_id' => 'wamid.ABC', 'cost_cents' => 18, 'destination_masked' => '+2782•••••67'])
        ->and(json_encode($message->getAttributes()))->not->toContain((string) $code)->not->toContain('821234567');
    expect(asTenant($this->tym, fn () => OtpChallenge::query()->sole()->channel))->toBe('whatsapp');
});

it('falls back from WhatsApp to SMS and between SMS providers in the admin order', function (): void {
    messagingProvider('whatsapp.meta', ['phone_number_id' => '1055', 'business_account_id' => '9'], ['access_token' => 't', 'app_secret' => 's']);
    messagingProvider('sms.clickatell', [], ['api_key' => 'ck'], 10);
    messagingProvider('sms.bulksms', ['token_id' => 'bid'], ['token_secret' => 'bsec'], 20);
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400),
        'platform.clickatell.com/*' => Http::response(['messages' => [['accepted' => false, 'error' => ['description' => 'No credit']]]], 202),
        'api.bulksms.com/*' => Http::response([['id' => '9001']], 201),
    ]);

    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->assertStatus(202);

    $log = asTenant($this->tym, fn () => Message::query()->orderBy('created_at')->orderBy('id')->get());
    expect($log->pluck('driver')->all())->toBe(['whatsapp.meta', 'sms.clickatell', 'sms.bulksms'])
        ->and($log->pluck('status')->all())->toBe(['failed', 'failed', 'sent'])
        ->and($log[0]->error)->toContain('Template not approved')
        ->and($log[1]->error)->toContain('No credit')
        ->and($log[2]->fallback_of)->toBe($log[1]->id);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.bulksms.com/v1/messages') && str_contains((string) $r['body'], 'Training Young Minds')
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('bid:bsec')));
    expect(asTenant($this->tym, fn () => OtpChallenge::query()->sole()->channel))->toBe('sms');
});

it('verifies the Meta webhook handshake and signed status updates', function (): void {
    $meta = messagingProvider('whatsapp.meta', ['phone_number_id' => '1055', 'business_account_id' => '9', 'verify_token' => 'my-verify'], ['access_token' => 't', 'app_secret' => 'appsecret']);
    $message = asPlatform(fn () => Message::query()->create(['channel' => 'whatsapp', 'driver' => 'whatsapp.meta', 'purpose' => 'otp', 'destination_hash' => 'h', 'destination_masked' => 'x', 'status' => 'sent', 'provider_message_id' => 'wamid.1']));

    $this->get('https://skills.test/v1/webhooks/messaging/whatsapp.meta?hub.mode=subscribe&hub.verify_token=my-verify&hub.challenge=4242')->assertOk()->assertSee('4242');
    $this->get('https://skills.test/v1/webhooks/messaging/whatsapp.meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=4242')->assertForbidden();

    $body = (string) json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.1', 'status' => 'delivered']]]]]]]]);
    $post = fn (string $secret) => $this->call('POST', 'https://skills.test/v1/webhooks/messaging/whatsapp.meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);

    $post('forged')->assertStatus(401);
    $post('appsecret')->assertOk()->assertSee('1');
    expect(asPlatform(fn () => Message::query()->findOrFail($message->id)->status))->toBe('delivered');
    expect($meta->id)->not->toBeEmpty();
});

it('accepts unsigned SMS receipts only with the secret callback key', function (): void {
    $click = messagingProvider('sms.clickatell', [], ['api_key' => 'ck']);
    asPlatform(fn () => Message::query()->create(['channel' => 'sms', 'driver' => 'sms.clickatell', 'purpose' => 'otp', 'destination_hash' => 'h', 'destination_masked' => 'x', 'status' => 'sent', 'provider_message_id' => 'api-1']));
    $body = ['event' => ['messageStatusUpdate' => [['messageId' => 'api-1', 'status' => 'DELIVERED_TO_GATEWAY']]]];

    $this->postJson('https://skills.test/v1/webhooks/messaging/sms.clickatell?key=nope', $body)->assertStatus(401);
    $this->postJson(CallbackKey::url($click), $body)->assertOk();
    expect(asPlatform(fn () => Message::query()->sole()->status))->toBe('delivered');
});

it('checks Twilio status callback signatures', function (): void {
    $twilio = messagingProvider('sms.twilio', ['account_sid' => 'AC1', 'from' => '+15005550006'], ['auth_token' => 'tw-token']);
    asPlatform(fn () => Message::query()->create(['channel' => 'sms', 'driver' => 'sms.twilio', 'purpose' => 'otp', 'destination_hash' => 'h', 'destination_masked' => 'x', 'status' => 'sent', 'provider_message_id' => 'SM1']));
    $params = ['MessageSid' => 'SM1', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003'];
    $url = CallbackKey::url($twilio);
    $sorted = $params;
    ksort($sorted);
    $signature = base64_encode(hash_hmac('sha1', $url.collect($sorted)->map(fn ($v, $k) => $k.$v)->implode(''), 'tw-token', true));

    $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => 'bad'])->assertStatus(401);
    $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $signature])->assertOk();
    expect(asPlatform(fn () => Message::query()->sole()->only(['status', 'error'])))->toBe(['status' => 'undelivered', 'error' => 'Twilio error 30003']);
});

it('sends through SMSPortal, Infobip, Panacea and Twilio with their own request shapes', function (string $driver, array $settings, array $credentials, array $fakes, Closure $check): void {
    messagingProvider($driver, $settings, $credentials);
    Http::fake(array_map(fn (array $r) => Http::response($r[1], $r[0]), $fakes));

    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567', 'channel' => 'sms'])->assertStatus(202);

    Http::assertSent($check);
    expect(asTenant($this->tym, fn () => Message::query()->sole()->status))->toBe('sent');
})->with(fn () => [
    'smsportal' => ['sms.smsportal', ['client_id' => 'cid'], ['api_secret' => 'sec'], [
        'rest.smsportal.com/Authentication' => [200, ['token' => 'sp-tok']],
        'rest.smsportal.com/v1/bulkmessages' => [200, ['eventId' => 77]],
    ], fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/bulkmessages') && $r->hasHeader('Authorization', 'Bearer sp-tok') && $r['messages'][0]['destination'] === '27821234567'],
    'infobip' => ['sms.infobip', ['base_url' => 'https://abc.api.infobip.com'], ['api_key' => 'ib'], [
        'abc.api.infobip.com/*' => [200, ['messages' => [['messageId' => 'ib-1']]]],
    ], fn (HttpRequest $r) => $r->url() === 'https://abc.api.infobip.com/sms/3/messages' && $r->hasHeader('Authorization', 'App ib') && $r['messages'][0]['destinations'][0]['to'] === '27821234567'],
    'panacea' => ['sms.panacea', ['username' => 'tym'], ['api_key' => 'pk'], [
        'api.panaceamobile.com/*' => [200, ['status' => 0, 'message' => 'OK', 'details' => 'pm-1']],
    ], fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.panaceamobile.com/json') && $r['action'] === 'message_send' && $r['to'] === '27821234567'],
    'twilio' => ['sms.twilio', ['account_sid' => 'AC1', 'from' => 'MG123'], ['auth_token' => 'tok'], [
        'api.twilio.com/*' => [201, ['sid' => 'SM9']],
    ], fn (HttpRequest $r) => str_ends_with($r->url(), '/Accounts/AC1/Messages.json') && $r['To'] === '+27821234567' && $r['MessagingServiceSid'] === 'MG123'],
]);

it('shows the message log, costs per provider and per training provider, and sends a test', function (): void {
    $bulk = messagingProvider('sms.bulksms', ['token_id' => 'bid', 'price_cents' => 25], ['token_secret' => 'bsec']);
    Http::fake(['api.bulksms.com/*' => Http::response([['id' => '1']], 201)]);
    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567', 'channel' => 'sms'])->assertStatus(202);

    signInStaff($this);
    $admin = 'https://admin.skills.test/v1/admin';
    $this->getJson($admin.'/messages')->assertOk()->assertJsonPath('data.0.driver', 'sms.bulksms')->assertJsonPath('data.0.tenant', 'Training Young Minds');
    $this->getJson($admin.'/messages?phone=0821234567')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($admin.'/messages?phone=0830000000')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($admin.'/messages/summary')->assertOk()
        ->assertJsonPath('by_driver.0.cost_cents', 25)->assertJsonPath('by_tenant.0.tenant', 'Training Young Minds');

    $this->postJson($admin."/settings/integrations/{$bulk->id}/send-test", ['to' => '083 555 1234'])->assertOk()->assertJsonPath('sent', true);
    $this->getJson($admin.'/settings/integrations')->assertOk();
    $callback = collect($this->getJson($admin.'/settings/integrations')->json('data'))->firstWhere('type', 'sms')['drivers'];
    expect(collect($callback)->firstWhere('driver', 'sms.bulksms')['integration']['callback_url'])->toStartWith('https://skills.test/v1/webhooks/messaging/sms.bulksms?key=');
});

it('sends email through the service chosen in Settings', function (): void {
    asPlatform(fn () => Integration::query()->create([
        'type' => 'email', 'driver' => 'email.smtp', 'enabled' => true,
        'settings' => ['host' => 'smtp.example.test', 'port' => 2525, 'encryption' => 'tls', 'username' => 'u', 'from_address' => 'noreply@skills.test', 'from_name' => 'Skills'],
        'credentials' => ['password' => 'p'],
    ]));

    $transport = app('mail.manager')->mailer('platform')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(EsmtpTransport::class)
        ->and((string) $transport)->toContain('smtp.example.test:2525');
});
