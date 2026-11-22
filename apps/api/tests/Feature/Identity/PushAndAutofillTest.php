<?php

declare(strict_types=1);

require_once __DIR__.'/../Billing/helpers.php';

use Illuminate\Support\Facades\Http;
use Modules\Identity\Models\Device;
use Modules\Identity\Otp\LogOtpChannel;
use Tests\Support\TestDevice;

beforeEach(function (): void {
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    makeMember($this->tym, ['learner'], ['phone_e164' => '+27821234567', 'first_name' => 'Ayanda']);
    $this->host = 'https://tym.skills.test/v1';
});

function appSignIn(object $test): string
{
    $challenge = $test->postJson($test->host.'/auth/otp/request', ['identifier' => '0821234567'])->json('challenge_id');
    preg_match('/^(\d{6})/', end(LogOtpChannel::$sent)['message'], $m);

    return (string) $test->postJson($test->host.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $m[1], 'device' => (new TestDevice)->payload()])
        ->assertOk()->json('access_token');
}

it('ends SMS codes with the Android app hash so the app reads them without a prompt', function (): void {
    config(['identity.otp.android_sms_hash' => 'FA+9qCX9VSu']);
    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->assertStatus(202);
    expect(end(LogOtpChannel::$sent)['message'])->toEndWith("\nFA+9qCX9VSu");

    config(['identity.otp.android_sms_hash' => 'not a hash']);
    $this->travel(1)->minutes();
    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->assertStatus(202);
    expect(end(LogOtpChannel::$sent)['message'])->not->toContain('not a hash');
});

it('registers the phone push token on its device and sends a test', function (): void {
    $token = appSignIn($this);
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->postJson($this->host.'/me/push-tokens', ['token' => 'nonsense'], $auth)->assertStatus(422);
    $this->postJson($this->host.'/me/push-tokens/test', [], $auth)->assertStatus(422);
    $this->postJson($this->host.'/me/push-tokens', ['token' => 'ExponentPushToken[abcdefghijklmnop]'], $auth)->assertOk();
    expect(asTenant($this->tym, fn () => Device::query()->sole()->push_token))->toBe('ExponentPushToken[abcdefghijklmnop]');

    Http::fake(['exp.host/*' => Http::sequence()
        ->push(['data' => [['status' => 'ok', 'id' => 'r1']]])
        ->push(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
    $this->postJson($this->host.'/me/push-tokens/test', [], $auth)->assertOk()->assertJsonPath('sent', true);
    Http::assertSent(fn ($r) => $r[0]['to'] === 'ExponentPushToken[abcdefghijklmnop]' && str_contains($r[0]['body'], 'Ayanda'));

    // Expo says the app is gone: the token is cleared.
    $this->postJson($this->host.'/me/push-tokens/test', [], $auth)->assertStatus(502);
    expect(asTenant($this->tym, fn () => Device::query()->sole()->push_token))->toBeNull();
});

it('keeps push tokens to app sessions', function (): void {
    signInOwner($this, $this->tym);
    $this->postJson($this->host.'/me/push-tokens', ['token' => 'ExponentPushToken[abcdefghijklmnop]'])->assertStatus(422);
});
