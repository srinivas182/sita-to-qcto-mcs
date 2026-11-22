<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Identity\Mfa\TotpService;
use Modules\Identity\Models\MfaFactor;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Notifications\ResetPasswordNotification;
use Modules\Identity\Support\MembershipStatus;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\TestDevice;

beforeEach(function (): void {
    $this->tym = makeProvider('tym');
    $this->other = makeProvider('other');
    $this->manager = makeMember($this->tym, ['centre-manager'], ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->base = 'https://tym.skills.test/v1';
    $this->web = webOrigin('tym.skills.test');
});

function login(object $test, array $body, array $headers): TestResponse
{
    return $test->withHeaders($headers)->postJson($test->base.'/auth/login', $body);
}

it('signs staff in with a session cookie on the web', function (): void {
    login($this, ['email' => 'Thandi@TYM.co.za', 'password' => 'correct horse battery staple'], $this->web)
        ->assertOk()->assertJson(['mode' => 'session', 'mfa_setup_required' => false]);

    $this->withHeaders($this->web)->getJson($this->base.'/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'thandi@tym.co.za')
        ->assertJsonPath('tenant.code', 'tym')
        ->assertJsonPath('roles.0.role', 'centre-manager')
        ->assertJsonPath('session.mode', 'session');

    $this->withHeaders($this->web)->postJson($this->base.'/auth/logout')->assertNoContent();
    $this->withHeaders($this->web)->getJson($this->base.'/me')->assertUnauthorized();
});

it('issues device tokens to the app', function (): void {
    login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple', 'device' => (new TestDevice)->payload()], [])
        ->assertOk()->assertJson(['mode' => 'token']);
});

it('rejects wrong passwords without revealing whether the account exists', function (): void {
    $wrong = login($this, ['email' => 'thandi@tym.co.za', 'password' => 'nope nope nope'], $this->web)->assertStatus(422);
    $unknown = login($this, ['email' => 'ghost@tym.co.za', 'password' => 'nope nope nope'], $this->web)->assertStatus(422);

    expect($wrong->json('error'))->toBe($unknown->json('error'));
});

it('locks the account with growing back-off', function (): void {
    foreach (range(1, 5) as $i) {
        login($this, ['email' => 'thandi@tym.co.za', 'password' => 'wrong'], $this->web)->assertStatus(422);
    }

    login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'], $this->web)
        ->assertStatus(429)->assertHeader('Retry-After', '60');

    $this->travel(61)->seconds();
    foreach (range(1, 5) as $i) {
        login($this, ['email' => 'thandi@tym.co.za', 'password' => 'wrong'], $this->web);
    }

    login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'], $this->web)
        ->assertStatus(429)->assertHeader('Retry-After', '300');
});

it('does not let a member of one provider sign in at another', function (): void {
    login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'], webOrigin('other.skills.test'))
        ->assertStatus(422); // invisible there: same answer as a wrong password

    // Sanity: the request really went to the other host.
    $this->withHeaders(webOrigin('other.skills.test'))
        ->postJson('https://other.skills.test/v1/auth/login', ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'])
        ->assertStatus(422);
});

it('blocks suspended memberships', function (): void {
    asTenant($this->tym, fn () => UserTenant::query()->where('user_id', $this->manager->id)->update(['status' => MembershipStatus::Suspended->value]));

    login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'], $this->web)
        ->assertForbidden()->assertJsonPath('error.code', 'not_member');
});

it('requires the authenticator code once MFA is set up, with replay protection and recovery codes', function (): void {
    $totp = app(TotpService::class);
    $g = app(Google2FA::class);
    $secret = asTenant($this->tym, fn () => $totp->begin($this->manager, 'TYM')['secret']);
    $codes = asTenant($this->tym, fn () => $totp->confirm($this->manager, $g->getCurrentOtp($secret)));

    // The enrolment code's time step is spent; pretend the next step has begun
    // (Google2FA reads the real clock, so Carbon time travel does not apply).
    asPlatform(fn () => MfaFactor::query()->where('user_id', $this->manager->id)->update(['last_timestep' => null]));

    $step = login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'], $this->web)
        ->assertOk()->assertJson(['mfa_required' => true]);

    $code = $g->getCurrentOtp($secret);
    $this->withHeaders($this->web)->postJson($this->base.'/auth/mfa/totp/challenge', ['mfa_token' => $step->json('mfa_token'), 'code' => $code])
        ->assertOk()->assertJson(['mode' => 'session']);

    // Same code again → replay rejected.
    $again = login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'], $this->web)->json('mfa_token');
    $this->withHeaders($this->web)->postJson($this->base.'/auth/mfa/totp/challenge', ['mfa_token' => $again, 'code' => $code])
        ->assertStatus(422);

    // Recovery code works exactly once.
    $this->withHeaders($this->web)->postJson($this->base.'/auth/mfa/totp/challenge', ['mfa_token' => $again, 'code' => $codes[0]])->assertOk();
    $third = login($this, ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'], $this->web)->json('mfa_token');
    $this->withHeaders($this->web)->postJson($this->base.'/auth/mfa/totp/challenge', ['mfa_token' => $third, 'code' => $codes[0]])->assertStatus(422);

    expect($codes)->toHaveCount(10)->and($totp->remainingRecoveryCodes($this->manager))->toBe(9);
});

it('makes owners set up MFA before using the console', function (): void {
    makeMember($this->tym, ['tp-owner'], ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);

    login($this, ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple'], $this->web)
        ->assertOk()->assertJson(['mfa_setup_required' => true]);

    $this->withHeaders($this->web)->getJson($this->base.'/me')->assertOk()->assertJsonPath('security.mfa_setup_required', true);
    $this->withHeaders($this->web)->getJson($this->base.'/roles')->assertForbidden()->assertJsonPath('error.code', 'mfa_setup_required');

    $secret = $this->withHeaders($this->web)->postJson($this->base.'/auth/mfa/totp/enable')->assertOk()->json('secret');
    $this->withHeaders($this->web)->postJson($this->base.'/auth/mfa/totp/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk()->assertJsonCount(10, 'recovery_codes');

    $this->withHeaders($this->web)->getJson($this->base.'/roles')->assertOk();
});

it('lets platform staff sign in only on the platform host', function (): void {
    makeStaff('super-admin', ['email' => 'admin@tym-mcs.co.za', 'password' => 'correct horse battery staple']);
    $admin = webOrigin('admin.skills.test');

    $this->withHeaders($admin)->postJson('https://admin.skills.test/v1/auth/login', ['email' => 'admin@tym-mcs.co.za', 'password' => 'correct horse battery staple'])
        ->assertOk()->assertJson(['mfa_setup_required' => true]);

    // A provider's staff member cannot use the platform host.
    $this->withHeaders($admin)->postJson('https://admin.skills.test/v1/auth/login', ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple'])
        ->assertForbidden();

    // Platform staff are not members of a provider: invisible on its host.
    login($this, ['email' => 'admin@tym-mcs.co.za', 'password' => 'correct horse battery staple'], $this->web)->assertStatus(422);
});

it('resets a forgotten password through an emailed link', function (): void {
    Notification::fake();

    $this->postJson($this->base.'/auth/password/forgot', ['email' => 'thandi@tym.co.za'])->assertStatus(202);
    $this->postJson($this->base.'/auth/password/forgot', ['email' => 'ghost@tym.co.za'])->assertStatus(202);

    $url = null;
    Notification::assertSentTo($this->manager, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$url): bool {
        $url = $n->url;

        return str_starts_with($n->url, 'https://tym.skills.test/reset-password?');
    });
    Notification::assertCount(1);

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $this->postJson($this->base.'/auth/password/reset', [
        'email' => 'thandi@tym.co.za', 'token' => $query['token'],
        'password' => 'a brand new passphrase 2026', 'password_confirmation' => 'a brand new passphrase 2026',
    ])->assertOk();

    login($this, ['email' => 'thandi@tym.co.za', 'password' => 'a brand new passphrase 2026'], $this->web)->assertOk();
    $this->postJson($this->base.'/auth/password/reset', [
        'email' => 'thandi@tym.co.za', 'token' => $query['token'],
        'password' => 'another passphrase 2026', 'password_confirmation' => 'another passphrase 2026',
    ])->assertStatus(422);
});

it('stores passwords with Argon2id', function (): void {
    $hash = asPlatform(fn () => User::query()->find($this->manager->id)?->getAuthPassword());

    expect($hash)->toStartWith('$argon2id$');
});
