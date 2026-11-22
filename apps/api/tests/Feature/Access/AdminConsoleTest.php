<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Modules\Access\Notifications\InvitationNotification;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Mfa\TotpService;
use Modules\Identity\Models\MfaFactor;
use Modules\Identity\Models\User;
use Modules\Tenancy\Domains\DnsResolver;
use Modules\Tenancy\Models\Cell;
use Modules\Tenancy\Models\TenantDomain;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeDns;

/** Sign a platform staff member in on the admin host with MFA set up. */
function signInStaff(object $test, string $role = 'super-admin', string $email = 'admin@tym-mcs.co.za'): User
{
    $user = makeStaff($role, ['email' => $email, 'password' => 'correct horse battery staple']);
    asPlatform(fn () => app(TotpService::class)->begin($user, 'Skills'));
    $secret = asPlatform(fn () => MfaFactor::query()->where('user_id', $user->id)->firstOrFail()->secret);
    app(TotpService::class)->confirm($user, app(Google2FA::class)->getCurrentOtp($secret));
    MfaFactor::query()->where('user_id', $user->id)->update(['last_timestep' => null]);

    $test->flushHeaders()->flushSession();
    $test->withHeaders(webOrigin('admin.skills.test'));
    $token = $test->postJson('https://admin.skills.test/v1/auth/login', ['email' => $email, 'password' => 'correct horse battery staple'])->json('mfa_token');
    $test->postJson('https://admin.skills.test/v1/auth/mfa/totp/challenge', ['mfa_token' => $token, 'code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

    return $user;
}

beforeEach(function (): void {
    Notification::fake();
    asPlatform(fn () => Cell::query()->firstOrCreate(['id' => 'cell-1'], ['name' => 'SA 1', 'region' => 'af-south-1']));
    $this->admin = 'https://admin.skills.test/v1/admin';
});

it('onboards a training provider end to end', function (): void {
    signInStaff($this);

    $tenant = $this->postJson($this->admin.'/tenants', [
        'code' => 'tym', 'legal_name' => 'Training Young Minds (Pty) Ltd', 'trading_name' => 'Training Young Minds',
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.domains.0.hostname', 'tym.skills.test')->json('data');

    $this->postJson($this->admin."/tenants/{$tenant['id']}/approve")->assertOk()->assertJsonPath('data.status', 'active');

    $this->postJson($this->admin."/tenants/{$tenant['id']}/invitations", ['email' => 'sekal@tym.co.za', 'role' => 'tp-owner'])->assertCreated();
    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => str_starts_with($n->url, 'https://tym.skills.test/invite/'));

    $dns = new FakeDns;
    $this->app->instance(DnsResolver::class, $dns);
    $domain = $this->postJson($this->admin."/tenants/{$tenant['id']}/domains", ['hostname' => 'learn.trainingyoungminds.co.za'])
        ->assertCreated()->assertJsonPath('data.dns_status', 'pending')->assertJsonCount(2, 'data.records')->json('data');

    $token = asPlatform(fn () => TenantDomain::query()->findOrFail($domain['id'])->verification_token);
    $dns->set('learn.trainingyoungminds.co.za', 'CNAME', ['edge.skills.test'])
        ->set('_skills-challenge.learn.trainingyoungminds.co.za', 'TXT', [$token]);

    $this->postJson($this->admin."/tenants/{$tenant['id']}/domains/{$domain['id']}/verify")
        ->assertOk()->assertJsonPath('check.passed', true)->assertJsonPath('domain.dns_status', 'verified')->assertJsonPath('domain.ssl_status', 'active');

    $this->getJson($this->admin.'/tenants?search=young')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->admin.'/overview')->assertOk()->assertJsonPath('tenants.active', 1);
    $this->getJson($this->admin.'/cells')->assertOk()->assertJsonPath('data.0.tenants', 1);

    $this->postJson($this->admin."/tenants/{$tenant['id']}/suspend", ['reason' => 'Accreditation lapsed'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->getJson('https://learn.trainingyoungminds.co.za/v1/branding')->assertForbidden();

    $actions = asPlatform(fn () => AuditLog::query()->where('subject_id', $tenant['id'])->pluck('action')->all());
    expect($actions)->toContain('tenant.created', 'tenant.updated');
});

it('lets the JV compliance desk look but not touch', function (): void {
    makeTenant(['code' => 'tym']);
    signInStaff($this, 'jv-compliance', 'compliance@tym-mcs.co.za');

    $this->getJson($this->admin.'/tenants')->assertOk();
    $this->getJson($this->admin.'/audit-logs')->assertOk();
    $this->postJson($this->admin.'/tenants', ['code' => 'nope', 'legal_name' => 'Nope'])->assertForbidden();
    $this->getJson($this->admin.'/staff')->assertForbidden();
});

it('keeps the admin console off provider hosts', function (): void {
    $tym = makeProvider('tym');
    makeMember($tym, ['tp-owner']);

    $this->getJson('https://tym.skills.test/v1/admin/tenants')->assertUnauthorized();
});

it('requires MFA before staff reach the console', function (): void {
    makeStaff('super-admin', ['email' => 'new@tym-mcs.co.za', 'password' => 'correct horse battery staple']);
    $this->withHeaders(webOrigin('admin.skills.test'))
        ->postJson('https://admin.skills.test/v1/auth/login', ['email' => 'new@tym-mcs.co.za', 'password' => 'correct horse battery staple'])
        ->assertJson(['mfa_setup_required' => true]);

    $this->getJson($this->admin.'/tenants')->assertForbidden()->assertJsonPath('error.code', 'mfa_setup_required');
});

it('manages platform staff', function (): void {
    $me = signInStaff($this);

    $this->postJson($this->admin.'/staff', ['email' => 'qcto@qcto.org.za', 'first_name' => 'Naledi', 'last_name' => 'Mokoena', 'role' => 'qcto-officer'])
        ->assertCreated();
    $this->postJson($this->admin.'/staff', ['email' => 'x@x.co.za', 'first_name' => 'X', 'last_name' => 'Y', 'role' => 'tp-owner'])
        ->assertStatus(422);

    $this->getJson($this->admin.'/staff')->assertOk()->assertJsonCount(2, 'data');
    $this->deleteJson($this->admin."/staff/{$me->id}/roles/super-admin")->assertStatus(422);
});
