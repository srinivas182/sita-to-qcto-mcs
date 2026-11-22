<?php

declare(strict_types=1);

require_once __DIR__.'/../Billing/helpers.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\Access\Models\Invitation;
use Modules\Access\Services\AccessService;
use Modules\Billing\Models\Subscription;
use Modules\Onboarding\Models\Application;
use Modules\Onboarding\Notifications\ApplicationDecided;
use Modules\Onboarding\Notifications\ApplicationEmailCode;
use Modules\Onboarding\Notifications\ApplicationNeedsInfo;
use Modules\Onboarding\Notifications\ApplicationReceived;
use Modules\Onboarding\Notifications\ApplicationToReview;
use Modules\Onboarding\Support\Identifiers;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Models\Cell;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Notifications\AccreditationExpiring;

const SITE = 'https://skills.test/v1';

beforeEach(function (): void {
    Notification::fake();
    companyReady();
    config(['files.default.root' => 'files-test-'.uniqid()]);
    $this->withHeaders(webOrigin('skills.test'));
});

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n".str_repeat('% filler line for a small pdf document', 20)."\n%%EOF\n";
}

/** Last email code sent to an address. */
function lastCode(string $email): string
{
    $code = null;
    Notification::assertSentOnDemand(ApplicationEmailCode::class, function (ApplicationEmailCode $n, array $channels, object $notifiable) use ($email, &$code): bool {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $code = $n->code;
        }

        return true;
    });

    return (string) $code;
}

/** @return array{id: string, token: string} */
function startApplication(object $test, string $email = 'thandi@ndlovu-skills.co.za'): array
{
    $res = $test->postJson(SITE.'/applications', ['first_name' => 'Thandi', 'last_name' => 'Ndlovu', 'email' => $email, 'legal_name' => 'Ndlovu Skills (Pty) Ltd'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.email_verified', false);

    return ['id' => $res->json('id'), 'token' => $res->json('token')];
}

/** Fill a complete, valid application. */
function completeApplication(object $test, array $app, string $email = 'thandi@ndlovu-skills.co.za'): void
{
    $h = ['X-Application-Token' => $app['token']];
    $test->postJson(SITE."/applications/{$app['id']}/verify-email", ['code' => lastCode($email)], $h)->assertOk()->assertJsonPath('data.email_verified', true);

    $test->patchJson(SITE."/applications/{$app['id']}", [
        'registration_number' => '2019/123456/07', 'vat_number' => '4123456780', 'website' => 'https://ndlovu-skills.co.za',
        'sdp_number' => '07-qcto/sdp180825105627', 'accreditation_expires_on' => now()->addYears(2)->toDateString(),
        'scope' => ['101869', '91785'], 'centre_name' => 'Soweto Campus', 'address_line1' => '12 Vilakazi St', 'city' => 'Soweto',
        'province' => 'Gauteng', 'postal_code' => '1804', 'preferred_code' => 'Ndlovu', 'package_id' => package('starter')->id, 'interval' => 'month',
    ], $h)->assertOk()->assertJsonPath('data.sdp_number', '07-QCTO/SDP180825105627')->assertJsonPath('data.preferred_code', 'ndlovu');

    $bytes = pdfBytes();
    $file = $test->postJson(SITE."/applications/{$app['id']}/files", ['kind' => 'accreditation_letter', 'name' => 'QCTO letter.pdf', 'mime' => 'application/pdf', 'size' => strlen($bytes)], $h)
        ->assertCreated()->json();
    expect($file['upload']['url'])->toEndWith("/v1/applications/{$app['id']}/files/{$file['file']['id']}/content");

    $test->call('PUT', $file['upload']['url'], [], [], [], [
        'CONTENT_TYPE' => 'application/pdf', 'HTTP_X_APPLICATION_TOKEN' => $app['token'], 'HTTP_ORIGIN' => 'https://skills.test', 'HTTP_REFERER' => 'https://skills.test/',
    ], $bytes)->assertOk();
    $test->postJson(SITE."/applications/{$app['id']}/files/{$file['file']['id']}/complete", [], $h)->assertOk()
        ->assertJsonPath('data.files.0.status', 'ready');

    $test->postJson(SITE."/applications/{$app['id']}/consent", ['accept' => true], $h)->assertOk()->assertJsonPath('data.consented', true);
}

it('checks SA identifiers', function (): void {
    expect(Identifiers::cipc('2019/123456/07'))->toBeTrue()
        ->and(Identifiers::cipc('1850/123456/07'))->toBeFalse()
        ->and(Identifiers::cipc('2019-123456-07'))->toBeFalse()
        ->and(Identifiers::qcto('07-QCTO/SDP180825105627'))->toBeTrue()
        ->and(Identifiers::qcto('07-qcto/ac-ttc180825105627'))->toBeTrue()
        ->and(Identifiers::qcto('SDP12345'))->toBeFalse()
        ->and(Identifiers::vat('4123456780'))->toBeTrue()
        ->and(Identifiers::vat('5123456780'))->toBeFalse();
});

it('takes an application from start to an approved, ready provider', function (): void {
    $admin = makeStaff('super-admin', ['email' => 'admin@tym-mcs.co.za']);
    asPlatform(fn () => Cell::factory()->create(['id' => 'cell-1', 'capacity_tenants' => 100]));
    $app = startApplication($this);
    $h = ['X-Application-Token' => $app['token']];

    // Not submittable until everything required passes.
    $this->postJson(SITE."/applications/{$app['id']}/submit", [], $h)->assertStatus(422);

    completeApplication($this, $app);
    $checks = collect($this->getJson(SITE."/applications/{$app['id']}", $h)->json('data.checks'));
    expect($checks['cipc_format']['ok'])->toBeTrue()->and($checks['code_available']['ok'])->toBeTrue()->and($checks['accreditation_current']['ok'])->toBeTrue();

    $this->postJson(SITE."/applications/{$app['id']}/submit", [], $h)->assertOk()->assertJsonPath('data.status', 'submitted');
    Notification::assertSentOnDemand(ApplicationReceived::class);
    Notification::assertSentOnDemand(ApplicationToReview::class, fn ($n, $c, $to) => $to->routes['mail'] === 'admin@tym-mcs.co.za');

    // Once submitted the applicant cannot change it.
    $this->patchJson(SITE."/applications/{$app['id']}", ['city' => 'Durban'], $h)->assertStatus(422);

    signInStaff($this, 'super-admin', 'reviewer@tym-mcs.co.za');
    $adminBase = 'https://admin.skills.test/v1/admin/applications';
    $this->getJson($adminBase)->assertOk()->assertJsonPath('data.0.reference', asPlatform(fn () => Application::query()->value('reference')))
        ->assertJsonPath('counts.submitted', 1);
    $detail = $this->getJson("{$adminBase}/{$app['id']}")->assertOk()->json('data');
    expect($detail['checklist'])->toHaveCount(4)->and($detail['files'])->toHaveCount(1);
    $this->get("{$adminBase}/{$app['id']}/files/{$detail['files'][0]['id']}")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Approval needs every review check ticked.
    $this->postJson("{$adminBase}/{$app['id']}/approve")->assertStatus(422);
    $this->putJson("{$adminBase}/{$app['id']}/review", ['checklist' => ['cipc_verified' => true, 'letter_matches' => true, 'scope_confirmed' => true, 'contact_confirmed' => true], 'notes' => 'Checked on CIPC BizPortal.'])->assertOk();
    $this->postJson("{$adminBase}/{$app['id']}/approve")->assertCreated()->assertJsonPath('tenant.code', 'ndlovu');

    $tenant = asPlatform(fn () => Tenant::query()->where('code', 'ndlovu')->sole());
    expect($tenant->status->value ?? $tenant->status)->toBe('active');
    asTenant($tenant, function () use ($tenant): void {
        $centre = Centre::query()->sole();
        expect($centre->name)->toBe('Soweto Campus')->and($centre->accreditation_number)->toBe('07-QCTO/SDP180825105627')->and($centre->scope)->toBe(['101869', '91785']);
        expect(Subscription::query()->sole()->status->value)->toBe('trialing');
        expect(Invitation::query()->where('email', 'thandi@ndlovu-skills.co.za')->exists())->toBeTrue();
        expect($tenant->id)->not->toBeEmpty();
    });
    Notification::assertSentOnDemand(ApplicationDecided::class, fn ($n) => $n->approved === true);
    $this->postJson("{$adminBase}/{$app['id']}/approve")->assertStatus(422);
});

it('asks for more information, lets the applicant fix it, and can decline', function (): void {
    $app = startApplication($this);
    completeApplication($this, $app);
    $h = ['X-Application-Token' => $app['token']];
    $this->postJson(SITE."/applications/{$app['id']}/submit", [], $h)->assertOk();

    signInStaff($this);
    $adminBase = 'https://admin.skills.test/v1/admin/applications';
    $this->postJson("{$adminBase}/{$app['id']}/request-info", ['message' => 'Please upload the full accreditation letter with all pages.'])->assertOk()
        ->assertJsonPath('data.status', 'info_requested');
    Notification::assertSentOnDemand(ApplicationNeedsInfo::class);

    $this->flushHeaders()->withHeaders(webOrigin('skills.test'));
    $this->getJson(SITE."/applications/{$app['id']}", $h)->assertJsonPath('data.message', 'Please upload the full accreditation letter with all pages.');
    $this->patchJson(SITE."/applications/{$app['id']}", ['city' => 'Johannesburg'], $h)->assertOk();
    $this->postJson(SITE."/applications/{$app['id']}/submit", [], $h)->assertOk()->assertJsonPath('data.status', 'submitted');

    signInStaff($this, 'super-admin', 'second@tym-mcs.co.za');
    $this->postJson("{$adminBase}/{$app['id']}/decline", ['reason' => 'The SDP number does not match the QCTO register.'])->assertOk()->assertJsonPath('data.status', 'declined');
    Notification::assertSentOnDemand(ApplicationDecided::class, fn ($n) => $n->approved === false);
    expect(asPlatform(fn () => Tenant::query()->where('code', 'ndlovu')->exists()))->toBeFalse();
});

it('flags bad formats, taken codes and duplicates', function (): void {
    makeProvider('tym');
    $app = startApplication($this);
    $h = ['X-Application-Token' => $app['token']];
    $this->patchJson(SITE."/applications/{$app['id']}", ['registration_number' => '2019-1', 'sdp_number' => 'SDP1', 'vat_number' => '999', 'preferred_code' => 'tym', 'accreditation_expires_on' => now()->subDay()->toDateString()], $h)->assertOk();
    $checks = collect($this->getJson(SITE."/applications/{$app['id']}", $h)->json('data.checks'));
    foreach (['cipc_format', 'sdp_format', 'vat_format', 'code_available', 'accreditation_current', 'email_verified'] as $key) {
        expect($checks[$key]['ok'])->toBeFalse($key);
    }

    $this->getJson(SITE.'/applications/code-available?code=tym')->assertOk()->assertJsonPath('available', false);
    $this->getJson(SITE.'/applications/code-available?code=admin')->assertJsonPath('available', false);
    $this->getJson(SITE.'/applications/code-available?code=ndlovu-skills')->assertJsonPath('available', true)->assertJsonPath('host', 'ndlovu-skills.skills.test');

    // A second application for an SDP already applying is a duplicate.
    asPlatform(fn () => Application::query()->whereKey($app['id'])->update(['status' => 'submitted', 'sdp_number' => '07-QCTO/SDP180825105627']));
    $other = startApplication($this, 'someone@else.co.za');
    $this->patchJson(SITE."/applications/{$other['id']}", ['sdp_number' => '07-QCTO/SDP180825105627'], ['X-Application-Token' => $other['token']])->assertOk();
    $checks = collect($this->getJson(SITE."/applications/{$other['id']}", ['X-Application-Token' => $other['token']])->json('data.checks'));
    expect($checks['not_duplicate']['ok'])->toBeFalse();
});

it('keeps applications private and off provider sites', function (): void {
    $tym = makeProvider('tym');
    $app = startApplication($this);
    $this->getJson(SITE."/applications/{$app['id']}")->assertNotFound();
    $this->getJson(SITE."/applications/{$app['id']}", ['X-Application-Token' => 'wrong'])->assertNotFound();
    $this->withHeaders(webOrigin('tym.skills.test'))->getJson("https://tym.skills.test/v1/applications/{$app['id']}", ['X-Application-Token' => $app['token']])->assertNotFound();

    // Bots filling the hidden field get a fake answer and nothing is saved.
    $this->withHeaders(webOrigin('skills.test'))->postJson(SITE.'/applications', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'bot@x.co', 'legal_name' => 'X', 'company_fax' => '1'])->assertCreated();
    expect(asPlatform(fn () => Application::query()->count()))->toBe(1);

    // Only platform staff can see the queue.
    signInOwner($this, $tym);
    expect($this->getJson('https://tym.skills.test/v1/admin/applications')->status())->toBeIn([403, 404]);
});

it('resumes with the reference and an email code, rotating the token', function (): void {
    $app = startApplication($this);
    $reference = asPlatform(fn () => Application::query()->sole()->reference);

    $this->postJson(SITE.'/applications/resume', ['reference' => $reference, 'email' => 'nobody@x.co.za'])->assertStatus(202);
    $this->postJson(SITE.'/applications/resume', ['reference' => strtolower($reference), 'email' => 'Thandi@Ndlovu-Skills.co.za'])->assertStatus(202);

    $this->postJson(SITE.'/applications/resume/verify', ['reference' => $reference, 'email' => 'thandi@ndlovu-skills.co.za', 'code' => '000000'])->assertStatus(422);
    $res = $this->postJson(SITE.'/applications/resume/verify', ['reference' => $reference, 'email' => 'thandi@ndlovu-skills.co.za', 'code' => lastCode('thandi@ndlovu-skills.co.za')])
        ->assertOk()->assertJsonPath('id', $app['id'])->assertJsonPath('data.email_verified', true);

    $this->getJson(SITE."/applications/{$app['id']}", ['X-Application-Token' => $app['token']])->assertNotFound();
    $this->getJson(SITE."/applications/{$app['id']}", ['X-Application-Token' => $res->json('token')])->assertOk();
});

it('manages centres and warns before accreditation ends', function (): void {
    $tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    signInOwner($this, $tym);
    $base = 'https://tym.skills.test/v1';

    $this->postJson("{$base}/centres", ['name' => 'Pretoria', 'accreditation_number' => 'bad'])->assertStatus(422)->assertJsonValidationErrors('accreditation_number');
    $id = $this->postJson("{$base}/centres", [
        'name' => 'Pretoria', 'accreditation_type' => 'sdp', 'accreditation_number' => '07-qcto/sdp180825105627',
        'accreditation_expires_on' => now()->addDays(45)->toDateString(), 'scope' => ['101869'], 'city' => 'Pretoria', 'latitude' => -25.74, 'longitude' => 28.19,
    ])->assertCreated()->assertJsonPath('data.expiry_state', 'soon')->assertJsonPath('data.accreditation_number', '07-QCTO/SDP180825105627')->json('data.id');
    $this->getJson("{$base}/centres")->assertOk()->assertJsonPath('data.0.days_to_expiry', 45);

    Artisan::call('centres:accreditation-alerts');
    Notification::assertSentOnDemandTimes(AccreditationExpiring::class, 1);
    Artisan::call('centres:accreditation-alerts');
    Notification::assertSentOnDemandTimes(AccreditationExpiring::class, 1); // same threshold: once only

    asTenant($tym, fn () => Centre::query()->whereKey($id)->update(['accreditation_expires_on' => now()->addDays(20)]));
    Artisan::call('centres:accreditation-alerts');
    Notification::assertSentOnDemandTimes(AccreditationExpiring::class, 2);

    // Renewed: alerts start afresh.
    $this->patchJson("{$base}/centres/{$id}", ['accreditation_expires_on' => now()->addYears(3)->toDateString()])->assertOk()->assertJsonPath('data.expiry_state', 'ok');
    expect(asTenant($tym, fn () => Centre::query()->whereKey($id)->value('alerted_for')))->toBeNull();

    // A learner can see centres but not change them.
    makeMember($tym, ['learner'], ['email' => 'learner@tym.test', 'password' => 'correct horse battery staple']);
    $this->flushHeaders()->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $this->postJson("{$base}/auth/login", ['email' => 'learner@tym.test', 'password' => 'correct horse battery staple'])->assertOk();
    $this->postJson("{$base}/centres", ['name' => 'Durban'])->assertForbidden();
});

it('queues emails that a worker can send without any tenant or platform context', function (): void {
    $app = startApplication($this);
    $model = asPlatform(fn () => Application::query()->findOrFail($app['id']));
    $tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $centre = asTenant($tym, fn () => Centre::query()->create(['name' => 'Pretoria', 'accreditation_number' => '07-QCTO/SDP180825105627', 'accreditation_expires_on' => now()->addDays(30)]));

    // What the queue does: serialise now, restore later in a worker with no context.
    $mails = [new ApplicationEmailCode($model, '123456'), new ApplicationReceived($model), new ApplicationToReview($model), new AccreditationExpiring($tym, $centre, 30)];
    foreach ($mails as $mail) {
        $restored = unserialize(serialize($mail));
        expect($restored->toMail(new stdClass)->render()->toHtml())->not->toBeEmpty();
    }
});

it('copies decisions to the applicant phone and marks approved providers as verified', function (): void {
    asPlatform(fn () => Cell::factory()->create(['id' => 'cell-1', 'capacity_tenants' => 100]));
    asPlatform(fn () => Integration::query()->create(['type' => 'sms', 'driver' => 'sms.bulksms', 'credentials' => ['username' => 'u', 'password' => 'p'], 'settings' => [], 'mode' => 'live', 'enabled' => true, 'priority' => 10]));
    Http::fake(['api.bulksms.com/*' => Http::response([['id' => 'b1', 'status' => ['type' => 'ACCEPTED']]], 201)]);

    $app = startApplication($this);
    completeApplication($this, $app);
    $h = ['X-Application-Token' => $app['token']];
    $this->patchJson(SITE."/applications/{$app['id']}", ['phone' => '082 555 1234'], $h)->assertOk();
    $this->postJson(SITE."/applications/{$app['id']}/submit", [], $h)->assertOk();

    signInStaff($this);
    $adminBase = 'https://admin.skills.test/v1/admin/applications';
    $this->putJson("{$adminBase}/{$app['id']}/review", ['checklist' => ['cipc_verified' => true, 'letter_matches' => true, 'scope_confirmed' => true, 'contact_confirmed' => true]])->assertOk();
    $this->postJson("{$adminBase}/{$app['id']}/approve")->assertCreated();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'bulksms') && str_contains(json_encode($r->data()), 'is approved'));

    $this->flushHeaders()->withHeaders(webOrigin('ndlovu.skills.test'));
    $this->getJson('https://ndlovu.skills.test/v1/branding')->assertOk()
        ->assertJsonPath('verified.accreditation_number', '07-QCTO/SDP180825105627');
    $this->getJson('https://admin.skills.test/v1/branding')->assertJsonMissingPath('verified');
});

it('shows centre managers only their own centres', function (): void {
    $tym = makeProvider('tym');
    [$a, $b] = asTenant($tym, fn () => [Centre::query()->create(['name' => 'Alpha']), Centre::query()->create(['name' => 'Beta'])]);
    $manager = makeMember($tym, [], ['email' => 'mgr@tym.test', 'password' => 'correct horse battery staple']);
    asTenant($tym, fn () => app(AccessService::class)->assign($manager, 'centre-manager', $b->id));

    $this->flushHeaders()->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $this->postJson('https://tym.skills.test/v1/auth/login', ['email' => 'mgr@tym.test', 'password' => 'correct horse battery staple'])->assertOk();
    $names = collect($this->getJson('https://tym.skills.test/v1/centres')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Beta']);
});
