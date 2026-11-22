<?php

declare(strict_types=1);

use Modules\Identity\Models\Passkey;
use Tests\Support\SoftAuthenticator;

beforeEach(function (): void {
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->other = makeProvider('other');
    $this->user = makeMember($this->tym, ['centre-manager'], ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->web = webOrigin('tym.skills.test');
    $this->origin = 'https://tym.skills.test';
    $this->authenticator = new SoftAuthenticator;

    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/login', ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'])->assertOk();
});

function registerPasskey(object $test): void
{
    $options = $test->withHeaders($test->web)->postJson($test->origin.'/v1/auth/passkeys/options')->assertOk()->json();

    expect($options['rp'])->toEqual(['name' => 'Training Young Minds', 'id' => 'tym.skills.test'])
        ->and($options['authenticatorSelection']['residentKey'])->toBe('required')
        ->and($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['attestation'])->toBe('none');

    $test->withHeaders($test->web)->postJson($test->origin.'/v1/auth/passkeys/register', [
        'credential' => $test->authenticator->create($options, $test->origin),
        'name' => 'MacBook Touch ID',
    ])->assertCreated()->assertJsonPath('name', 'MacBook Touch ID');
}

it('registers a passkey and signs in with it, no password needed', function (): void {
    registerPasskey($this);
    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/logout')->assertNoContent();

    $start = $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/passkeys/login/options')->assertOk()->json();
    expect($start['options']['allowCredentials'] ?? [])->toBe([]);

    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/passkeys/login', [
        'session' => $start['session'],
        'credential' => $this->authenticator->get($start['options'], $this->origin),
    ])->assertOk()->assertJson(['mode' => 'session']);

    $this->withHeaders($this->web)->getJson($this->origin.'/v1/me')->assertOk()->assertJsonPath('security.passkeys', 1);

    $passkey = Passkey::query()->firstOrFail();
    expect($passkey->sign_count)->toBe(1)->and($passkey->last_used_at)->not->toBeNull();
});

it('rejects a replayed assertion and a reused challenge', function (): void {
    registerPasskey($this);

    $start = $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/passkeys/login/options')->json();
    $assertion = $this->authenticator->get($start['options'], $this->origin);
    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/passkeys/login', ['session' => $start['session'], 'credential' => $assertion])->assertOk();

    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/passkeys/login', ['session' => $start['session'], 'credential' => $assertion])
        ->assertStatus(422)->assertJsonPath('error.code', 'passkey_failed');
});

it('rejects an assertion made for another site', function (): void {
    registerPasskey($this);

    $start = $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/passkeys/login/options')->json();
    $phished = $this->authenticator->get($start['options'], 'https://tym-skills.evil.example');

    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/passkeys/login', ['session' => $start['session'], 'credential' => $phished])
        ->assertStatus(422);
});

it('does not let a passkey open another provider', function (): void {
    registerPasskey($this);
    $otherOrigin = 'https://other.skills.test';

    $start = $this->withHeaders(webOrigin('other.skills.test'))->postJson($otherOrigin.'/v1/auth/passkeys/login/options')->json();
    $start['options']['rpId'] = 'tym.skills.test'; // attacker rewrites the RP id

    $this->withHeaders(webOrigin('other.skills.test'))->postJson($otherOrigin.'/v1/auth/passkeys/login', [
        'session' => $start['session'],
        'credential' => $this->authenticator->get($start['options'], $otherOrigin),
    ])->assertStatus(422);
});

it('counts a passkey as the second factor that owners need', function (): void {
    makeMember($this->tym, ['tp-owner'], ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/logout');
    $this->withHeaders($this->web)->postJson($this->origin.'/v1/auth/login', ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple'])
        ->assertJson(['mfa_setup_required' => true]);

    $this->withHeaders($this->web)->getJson($this->origin.'/v1/roles')->assertForbidden();
    registerPasskey($this);
    $this->withHeaders($this->web)->getJson($this->origin.'/v1/roles')->assertOk();
});

it('lists and removes passkeys', function (): void {
    registerPasskey($this);

    $id = $this->withHeaders($this->web)->getJson($this->origin.'/v1/auth/passkeys')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    $this->withHeaders($this->web)->deleteJson($this->origin.'/v1/auth/passkeys/'.$id)->assertNoContent();

    expect(Passkey::query()->count())->toBe(0);
});
