<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Modules\Access\Services\SupportAccessService;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Mfa\TotpService;
use Modules\Identity\Models\MfaFactor;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym');
    $this->owner = makeMember($this->tym, ['tp-owner'], ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->learner = makeMember($this->tym, ['learner']);
    $this->staff = makeStaff('super-admin', ['email' => 'support@tym-mcs.co.za', 'password' => 'correct horse battery staple']);

    foreach ([$this->owner, $this->staff] as $u) {
        $secret = app(TotpService::class)->begin($u, 'x')['secret'];
        app(TotpService::class)->confirm($u, app(Google2FA::class)->getCurrentOtp($secret));
        MfaFactor::query()->where('user_id', $u->id)->update(['last_timestep' => null]);
        $this->secrets[$u->id] = $secret;
    }
});

function signInAt(object $test, string $host, string $email, string $secret): void
{
    $test->flushHeaders()->flushSession();
    $test->withHeaders(webOrigin($host));
    $token = $test->postJson("https://{$host}/v1/auth/login", ['email' => $email, 'password' => 'correct horse battery staple'])->json('mfa_token');
    $test->postJson("https://{$host}/v1/auth/mfa/totp/challenge", ['mfa_token' => $token, 'code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();
    MfaFactor::query()->update(['last_timestep' => null]);
}

it('lets staff in only after the owner approves, read-only and time-boxed', function (): void {
    // Without a grant the provider is closed to platform staff.
    signInAt($this, 'admin.skills.test', 'support@tym-mcs.co.za', $this->secrets[$this->staff->id]);
    $this->getJson('https://admin.skills.test/v1/users', ['X-Tenant' => 'tym'])->assertForbidden()->assertJsonPath('error.code', 'not_member');

    $grant = $this->postJson('https://admin.skills.test/v1/admin/support-access', [
        'tenant_id' => $this->tym->id, 'reason' => 'Learner cannot see their course', 'minutes' => 60,
    ])->assertCreated()->assertJsonPath('status', 'pending')->json();

    $this->getJson('https://admin.skills.test/v1/users', ['X-Tenant' => 'tym'])->assertForbidden();

    // Owner approves on their own host.
    signInAt($this, 'tym.skills.test', 'sekal@tym.co.za', $this->secrets[$this->owner->id]);
    $this->getJson('https://tym.skills.test/v1/support-access')->assertOk()->assertJsonPath('data.0.reason', 'Learner cannot see their course');
    $this->postJson("https://tym.skills.test/v1/support-access/{$grant['id']}/approve")->assertOk()->assertJsonPath('status', 'active');

    // Staff can now read, but not change settings.
    signInAt($this, 'admin.skills.test', 'support@tym-mcs.co.za', $this->secrets[$this->staff->id]);
    $this->getJson('https://admin.skills.test/v1/users', ['X-Tenant' => 'tym'])->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('https://admin.skills.test/v1/me', ['X-Tenant' => 'tym'])->assertOk()->assertJsonPath('support_access.grant_id', $grant['id']);
    $this->patchJson('https://admin.skills.test/v1/tenant/branding', ['branding' => ['primary' => '#000000']], ['X-Tenant' => 'tym'])->assertForbidden();

    // The provider sees the visit in its own audit trail.
    $visits = asTenant($this->tym, fn () => AuditLog::query()->where('action', 'support_access.used')->get());
    expect($visits)->toHaveCount(1)
        ->and($visits->first()->actor_id)->toBe($this->staff->id)
        ->and($visits->first()->meta['support_grant'])->toBe($grant['id']);

    // Window ends.
    $this->travel(61)->minutes();
    $this->getJson('https://admin.skills.test/v1/users', ['X-Tenant' => 'tym'])->assertForbidden();
});

it('lets the owner deny or end access early', function (): void {
    $grant = asPlatform(fn () => app(SupportAccessService::class)->request($this->staff, $this->tym, 'Checking a report issue', 30));

    signInAt($this, 'tym.skills.test', 'sekal@tym.co.za', $this->secrets[$this->owner->id]);
    $this->postJson("https://tym.skills.test/v1/support-access/{$grant->id}/approve")->assertOk();
    $this->postJson("https://tym.skills.test/v1/support-access/{$grant->id}/revoke")->assertOk()->assertJsonPath('status', 'revoked');
    $this->postJson("https://tym.skills.test/v1/support-access/{$grant->id}/approve")->assertStatus(409);

    signInAt($this, 'admin.skills.test', 'support@tym-mcs.co.za', $this->secrets[$this->staff->id]);
    $this->getJson('https://admin.skills.test/v1/users', ['X-Tenant' => 'tym'])->assertForbidden();
});

it('caps the window length', function (): void {
    signInAt($this, 'admin.skills.test', 'support@tym-mcs.co.za', $this->secrets[$this->staff->id]);

    $this->postJson('https://admin.skills.test/v1/admin/support-access', ['tenant_id' => $this->tym->id, 'reason' => 'Long investigation here', 'minutes' => 600])
        ->assertStatus(422)->assertJsonValidationErrors('minutes');
});
