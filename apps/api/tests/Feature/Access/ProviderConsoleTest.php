<?php

declare(strict_types=1);

use Modules\Tenancy\Models\Centre;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds', 'branding' => ['primary' => '#4338ca']]);
    $this->other = makeProvider('other');
    $this->centre = asPlatform(fn () => Centre::factory()->create(['tenant_id' => $this->tym->id]));
    $this->owner = makeMember($this->tym, ['tp-owner'], ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple', 'first_name' => 'Sekal', 'last_name' => 'Ndlovu']);
    $this->learner = makeMember($this->tym, [['learner', $this->centre->id]], ['first_name' => 'Ayanda', 'last_name' => 'Zulu']);
    makeMember($this->other, ['learner'], ['first_name' => 'Other', 'last_name' => 'Person']);

    $this->withHeaders(webOrigin('tym.skills.test'));
    $this->postJson('https://tym.skills.test/v1/auth/login', ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);
    $secret = $this->postJson('https://tym.skills.test/v1/auth/mfa/totp/enable')->json('secret');
    $this->postJson('https://tym.skills.test/v1/auth/mfa/totp/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
    $this->base = 'https://tym.skills.test/v1';
});

it('serves public branding for the sign-in page', function (): void {
    $this->flushHeaders()->getJson($this->base.'/branding')
        ->assertOk()->assertJson(['name' => 'Training Young Minds', 'branding' => ['primary' => '#4338ca']])
        ->assertHeader('Cache-Control', 'max-age=60, public, stale-while-revalidate=600');

    $this->getJson('https://admin.skills.test/v1/branding')->assertOk()->assertJson(['platform' => true]);
});

it('updates branding with validation', function (): void {
    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['primary' => '#0f766e', 'tagline' => 'Build your future']])
        ->assertOk()->assertJsonPath('data.branding.primary', '#0f766e');

    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['primary' => 'red']])->assertStatus(422);
    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['logo_url' => 'http://insecure.example/logo.png']])->assertStatus(422);
});

it('lists only this provider people and searches them', function (): void {
    $this->getJson($this->base.'/users')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson($this->base.'/users?search=ayan')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ayanda Zulu');
    $this->getJson($this->base.'/users?role=learner')->assertOk()->assertJsonCount(1, 'data');
});

it('changes roles but not your own', function (): void {
    $this->putJson($this->base."/users/{$this->learner->id}/roles", ['roles' => [
        ['role' => 'learner', 'centre_id' => $this->centre->id],
        ['role' => 'facilitator', 'centre_id' => $this->centre->id],
    ]])->assertOk()->assertJsonCount(2, 'roles');

    $this->putJson($this->base."/users/{$this->owner->id}/roles", ['roles' => []])->assertForbidden();
    $this->putJson($this->base."/users/{$this->learner->id}/roles", ['roles' => [['role' => 'super-admin']]])->assertForbidden();
});

it('lists the role catalogue with what the caller may assign', function (): void {
    $roles = collect($this->getJson($this->base.'/roles')->assertOk()->json('data'))->keyBy('key');

    expect($roles)->toHaveCount(17)
        ->and($roles['facilitator']['assignable'])->toBeTrue()
        ->and($roles['super-admin']['assignable'])->toBeFalse();
});

it('shows the provider its own audit trail only', function (): void {
    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['tagline' => 'Hello']]);

    $entries = collect($this->getJson($this->base.'/audit-logs?per_page=200')->assertOk()->json('data'));

    expect($entries)->not->toBeEmpty()
        ->and($entries->pluck('tenant_id')->unique()->values()->all())->toBe([$this->tym->id])
        ->and($entries->pluck('action'))->toContain('tenant.updated');

    $this->getJson($this->base.'/audit-logs?action=tenant.')->assertOk()->assertJsonPath('data.0.action', 'tenant.updated');
});

it('denies learners the console', function (): void {
    $this->getJson($this->base.'/me')->assertJsonPath('security.totp', true)->assertJsonFragment(['tenant.settings.manage']);

    $learner = makeMember($this->tym, ['learner'], ['email' => 'learner@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->postJson($this->base.'/auth/logout');
    $this->postJson($this->base.'/auth/login', ['email' => 'learner@tym.co.za', 'password' => 'correct horse battery staple']);

    $this->getJson($this->base.'/users')->assertForbidden();
    $this->getJson($this->base.'/audit-logs')->assertForbidden();
    $this->patchJson($this->base.'/tenant/branding', ['trading_name' => 'Hacked'])->assertForbidden();
    $this->getJson($this->base."/tenants/{$this->tym->id}/domains")->assertForbidden();
    $this->getJson($this->base.'/me')->assertOk()->assertJsonPath('permissions', ['learning.self']);
});

it('records and withdraws POPIA consents', function (): void {
    $this->postJson($this->base.'/me/consents', ['purpose' => 'privacy', 'version' => '2026-11'])->assertCreated();
    $this->postJson($this->base.'/me/consents', ['purpose' => 'privacy', 'version' => '2026-11'])->assertOk();
    $this->postJson($this->base.'/me/consents', ['purpose' => 'anything', 'version' => '1'])->assertStatus(422);

    $this->deleteJson($this->base.'/me/consents/privacy')->assertOk()->assertJson(['withdrawn' => 1]);
    $this->getJson($this->base.'/me/consents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.purpose', 'privacy');
    expect($this->getJson($this->base.'/me/consents')->json('data.0.withdrawn_at'))->not->toBeNull();
});

it('sends security headers on every response', function (): void {
    $this->getJson($this->base.'/me')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload')
        ->assertHeader('Content-Security-Policy');
});

it('manages domains for its own provider only', function (): void {
    $this->postJson($this->base."/tenants/{$this->tym->id}/domains", ['hostname' => 'learn.tym.co.za'])->assertCreated();
    $this->getJson($this->base."/tenants/{$this->tym->id}/domains")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($this->base."/tenants/{$this->other->id}/domains")->assertNotFound();
    $this->postJson($this->base."/tenants/{$this->tym->id}/domains", ['hostname' => 'evil.skills.test'])
        ->assertStatus(422)->assertJsonPath('error.code', 'domain_reserved');
});
