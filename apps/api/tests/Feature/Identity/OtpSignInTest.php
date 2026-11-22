<?php

declare(strict_types=1);

use Modules\Identity\Models\OtpChallenge;
use Modules\Identity\Models\User;
use Modules\Identity\Otp\LogOtpChannel;
use Modules\Identity\Otp\OtpChannel;
use Modules\Identity\Otp\OtpDispatcher;
use Modules\Identity\Support\IdType;
use Tests\Support\TestDevice;

beforeEach(function (): void {
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->other = makeProvider('other');
    $this->learner = makeMember($this->tym, ['learner'], ['phone_e164' => '+27821234567']);
    asPlatform(function (): void {
        $this->learner->setIdentityNumber(IdType::SaId, '8001015009087');
        $this->learner->save();
    });
    $this->host = 'https://tym.skills.test/v1';
});

function lastPin(): string
{
    $message = end(LogOtpChannel::$sent)['message'] ?? '';
    preg_match('/^(\d{6})/', $message, $m);

    return $m[1] ?? '';
}

it('sends a WhatsApp PIN and signs the learner in on the app', function (): void {
    $request = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '082 123 4567'])
        ->assertStatus(202)
        ->assertJsonStructure(['challenge_id', 'expires_in', 'resend_after']);

    expect(LogOtpChannel::$sent)->toHaveCount(1)
        ->and(LogOtpChannel::$sent[0]['channel'])->toBe('whatsapp')
        ->and(LogOtpChannel::$sent[0]['destination'])->toBe('+27821234567')
        ->and(LogOtpChannel::$sent[0]['message'])->toContain('Training Young Minds')
        ->and(LogOtpChannel::$sent[0]['message'])->toContain('@tym.skills.test #');

    $device = new TestDevice;
    $this->postJson($this->host.'/auth/otp/verify', [
        'challenge_id' => $request->json('challenge_id'),
        'code' => lastPin(),
        'device' => $device->payload(),
    ])->assertOk()
        ->assertJson(['mode' => 'token', 'token_type' => 'Bearer', 'mfa_setup_required' => false])
        ->assertJsonStructure(['access_token', 'refresh_token', 'device_id', 'offline_until', 'expires_at']);
});

it('accepts the SA ID number as the identifier', function (): void {
    $challenge = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '800101 5009 087'])->json('challenge_id');

    expect(LogOtpChannel::$sent)->toHaveCount(1);

    $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => lastPin(), 'device' => (new TestDevice)->payload()])
        ->assertOk();
});

it('looks the same for unknown people and sends nothing', function (): void {
    $known = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->json();
    $this->travel(31)->seconds();
    $unknown = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0839999999'])->assertStatus(202)->json();

    expect(array_keys($unknown))->toBe(array_keys($known))
        ->and(LogOtpChannel::$sent)->toHaveCount(1);

    $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $unknown['challenge_id'], 'code' => '123456'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_code');
});

it('does not send a PIN for someone who belongs to another provider', function (): void {
    makeMember($this->other, ['learner'], ['phone_e164' => '+27831112222']);

    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0831112222'])->assertStatus(202);

    expect(LogOtpChannel::$sent)->toBe([]);
});

it('locks a challenge after five wrong codes', function (): void {
    $challenge = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->json('challenge_id');
    $pin = lastPin();
    $wrong = $pin === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $wrong])->assertStatus(422);
    }

    $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $pin, 'device' => (new TestDevice)->payload()])
        ->assertStatus(422);
});

it('expires PINs and makes them single use', function (): void {
    $challenge = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->json('challenge_id');
    $pin = lastPin();
    $device = (new TestDevice)->payload();

    $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $pin, 'device' => $device])->assertOk();
    $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $pin, 'device' => $device])->assertStatus(422);

    $this->travel(31)->seconds();
    $second = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->json('challenge_id');
    $this->travel(6)->minutes();
    $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $second, 'code' => lastPin(), 'device' => $device])->assertStatus(422);
});

it('enforces a resend wait and an hourly cap', function (): void {
    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->assertStatus(202);
    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])
        ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'too_many_attempts');

    foreach (range(2, 5) as $i) {
        $this->travel(31)->seconds();
        $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->assertStatus(202);
    }

    $this->travel(31)->seconds();
    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->assertStatus(429);
});

it('falls back to SMS when WhatsApp fails', function (): void {
    $sms = new LogOtpChannel('sms');
    $this->app->instance(OtpDispatcher::class, new OtpDispatcher([
        new class implements OtpChannel
        {
            public function name(): string
            {
                return 'whatsapp';
            }

            public function send(string $destination, string $message, ?string $code = null): bool
            {
                throw new RuntimeException('provider down');
            }
        },
        $sms,
    ]));

    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->assertStatus(202);

    expect(LogOtpChannel::$sent[0]['channel'])->toBe('sms')
        ->and(asTenant($this->tym, fn () => OtpChallenge::query()->latest()->value('channel')))->toBe('sms');
});

it('requires a device when signing in from the app', function (): void {
    $challenge = $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567'])->json('challenge_id');

    $this->postJson($this->host.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => lastPin()])
        ->assertStatus(422)->assertJsonPath('error.code', 'device_required');
});

it('never stores the PIN or the identity number in the clear', function (): void {
    $this->postJson($this->host.'/auth/otp/request', ['identifier' => '0821234567']);
    $pin = lastPin();

    $row = asPlatform(fn () => DB::table('otp_challenges')->first());
    $user = asPlatform(fn () => DB::table('users')->where('id', $this->learner->id)->first());

    expect($row->code_hash)->not->toContain($pin)
        ->and($user->id_number)->not->toContain('8001015009087')
        ->and($user->id_number_hash)->toHaveLength(64)
        ->and(asPlatform(fn () => User::query()->find($this->learner->id)?->maskedIdNumber()))->toBe('800101•••••87');
});
