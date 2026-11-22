<?php

declare(strict_types=1);

use Modules\Audit\Models\AuditLog;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\PersonalAccessToken;
use Tests\Support\TestDevice;

beforeEach(function (): void {
    $this->tym = makeProvider('tym');
    $this->other = makeProvider('other');
    $this->user = makeMember($this->tym, ['facilitator'], ['email' => 'lwazi@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->phone = new TestDevice;
    $this->pair = $this->postJson('https://tym.skills.test/v1/auth/login', [
        'email' => 'lwazi@tym.co.za', 'password' => 'correct horse battery staple', 'device' => $this->phone->payload(),
    ])->assertOk()->json();
});

function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

it('authenticates API calls with the access token and grants 14 days offline', function (): void {
    $this->getJson('https://tym.skills.test/v1/me', bearer($this->pair['access_token']))
        ->assertOk()->assertJsonPath('session.mode', 'token')->assertJsonPath('user.email', 'lwazi@tym.co.za');

    expect(now()->diffInDays($this->pair['offline_until']))->toBeGreaterThanOrEqual(13.9)
        ->and(now()->diffInMinutes($this->pair['expires_at']))->toBeLessThanOrEqual(15.0);
});

it('works on the platform API host with the X-Tenant header (mobile app)', function (): void {
    $this->getJson('https://api.skills.test/v1/me', [...bearer($this->pair['access_token']), 'X-Tenant' => 'tym'])
        ->assertOk()->assertJsonPath('tenant.code', 'tym');
});

it('rejects the token for another provider', function (): void {
    $this->getJson('https://other.skills.test/v1/me', bearer($this->pair['access_token']))->assertUnauthorized();
    $this->getJson('https://api.skills.test/v1/me', [...bearer($this->pair['access_token']), 'X-Tenant' => 'other'])->assertUnauthorized();
});

it('expires access tokens after 15 minutes', function (): void {
    $this->travel(16)->minutes();

    $this->getJson('https://tym.skills.test/v1/me', bearer($this->pair['access_token']))->assertUnauthorized();
});

it('rotates tokens on refresh with a device signature', function (): void {
    $next = $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $this->phone->refreshRequest($this->pair['device_id'], $this->pair['refresh_token']))
        ->assertOk()->json();

    expect($next['refresh_token'])->not->toBe($this->pair['refresh_token'])
        ->and($next['device_id'])->toBe($this->pair['device_id']);

    $this->getJson('https://tym.skills.test/v1/me', bearer($this->pair['access_token']))->assertUnauthorized();
    $this->getJson('https://tym.skills.test/v1/me', bearer($next['access_token']))->assertOk();
});

it('refuses refresh without the device key', function (): void {
    $thief = new TestDevice;

    $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $thief->refreshRequest($this->pair['device_id'], $this->pair['refresh_token']))
        ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $this->phone->refreshRequest($this->pair['device_id'], $this->pair['refresh_token'], time() - 3600))
        ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');
});

it('signs the device out when a spent refresh token is replayed', function (): void {
    $request = $this->phone->refreshRequest($this->pair['device_id'], $this->pair['refresh_token']);
    $next = $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $request)->assertOk()->json();

    $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $this->phone->refreshRequest($this->pair['device_id'], $this->pair['refresh_token']))
        ->assertUnauthorized()->assertJsonPath('error.code', 'token_reused');

    $this->getJson('https://tym.skills.test/v1/me', bearer($next['access_token']))->assertUnauthorized();
    $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $this->phone->refreshRequest($this->pair['device_id'], $next['refresh_token']))
        ->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');

    expect(asTenant($this->tym, fn () => AuditLog::query()->where('action', 'auth.refresh_reused')->count()))->toBe(1);
});

it('revokes a device remotely and tells the app to wipe', function (): void {
    $laptop = $this->postJson('https://tym.skills.test/v1/auth/login', [
        'email' => 'lwazi@tym.co.za', 'password' => 'correct horse battery staple', 'device' => (new TestDevice('ios', 'iPhone'))->payload(),
    ])->json();

    $this->getJson('https://tym.skills.test/v1/auth/devices', bearer($laptop['access_token']))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['current' => true, 'name' => 'iPhone']);

    $this->deleteJson('https://tym.skills.test/v1/auth/devices/'.$this->pair['device_id'], [], bearer($laptop['access_token']))->assertNoContent();

    $this->getJson('https://tym.skills.test/v1/me', bearer($this->pair['access_token']))->assertUnauthorized();
    $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $this->phone->refreshRequest($this->pair['device_id'], $this->pair['refresh_token']))
        ->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
});

it('reuses the device record when the same key signs in again', function (): void {
    $this->postJson('https://tym.skills.test/v1/auth/login', [
        'email' => 'lwazi@tym.co.za', 'password' => 'correct horse battery staple', 'device' => $this->phone->payload(),
    ])->assertOk()->assertJsonPath('device_id', $this->pair['device_id']);

    expect(asTenant($this->tym, fn () => Device::query()->count()))->toBe(1)
        ->and(asTenant($this->tym, fn () => PersonalAccessToken::query()->count()))->toBe(1);
});

it('ends the device session on sign-out', function (): void {
    $this->postJson('https://tym.skills.test/v1/auth/logout', [], bearer($this->pair['access_token']))->assertNoContent();

    $this->getJson('https://tym.skills.test/v1/me', bearer($this->pair['access_token']))->assertUnauthorized();
    $this->postJson('https://tym.skills.test/v1/auth/token/refresh', $this->phone->refreshRequest($this->pair['device_id'], $this->pair['refresh_token']))
        ->assertUnauthorized();
});

it('rejects malformed device keys', function (): void {
    $this->postJson('https://tym.skills.test/v1/auth/login', [
        'email' => 'lwazi@tym.co.za', 'password' => 'correct horse battery staple',
        'device' => ['platform' => 'android', 'name' => 'x', 'public_key' => base64_encode('short')],
    ])->assertStatus(422)->assertJsonValidationErrors('device.public_key');
});
