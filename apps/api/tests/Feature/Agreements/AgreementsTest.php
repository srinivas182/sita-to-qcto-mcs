<?php

declare(strict_types=1);

require_once __DIR__.'/../People/helpers.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Notifications\AgreementMail;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Otp\LogOtpChannel;
use Modules\Settings\Models\Integration;

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds', 'legal_name' => 'Training Young Minds (Pty) Ltd']);
    $this->q = catalogueEntry();
    signInOwner($this, $this->tym);
    [$this->employer, $this->site, $this->mentor] = asTenant($this->tym, function () {
        $e = Employer::query()->create(['legal_name' => 'Mabaso Builders (Pty) Ltd', 'registration_number' => '2015/123456/07', 'sdl_number' => 'L123456789']);
        $s = EmployerSite::query()->create(['employer_id' => $e->id, 'name' => 'Soweto yard', 'city' => 'Soweto', 'province' => 'Gauteng', 'approval_number' => 'CETA/WPA/1',
            'approval_body' => 'CETA', 'approved_on' => now()->subYear(), 'approval_expires_on' => now()->addYear(), 'approved_qualification_ids' => [$this->q->id]]);
        $m = Mentor::query()->create(['employer_id' => $e->id, 'name' => 'Bheki Mabaso', 'designation' => 'Foreman', 'years_experience' => 14, 'site_ids' => [$s->id]]);

        return [$e, $s, $m];
    });
});

/** Publish the standard workplace agreement and return its id. */
function workplaceTemplate(object $test, bool $advanced = false): string
{
    $id = $test->postJson(TYM_V1.'/agreement-templates', ['starter' => 'workplace'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    if ($advanced) {
        $test->patchJson(TYM_V1."/agreement-templates/{$id}", ['advanced_signature' => true])->assertOk();
    }
    $test->postJson(TYM_V1."/agreement-templates/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

    return $id;
}

function sendWorkplace(object $test, string $template, array $overrides = []): TestResponse
{
    return $test->postJson(TYM_V1.'/agreements', [
        'template_id' => $template, 'employer_id' => $test->employer->id, 'site_id' => $test->site->id, 'mentor_id' => $test->mentor->id,
        'qualification_id' => $test->q->id, 'start_date' => '2027-02-01', 'end_date' => '2027-11-30',
        'signers' => [
            ['role' => 'learner', 'name' => 'Ayanda Zulu', 'phone' => '082 123 4567'],
            ['role' => 'employer', 'name' => 'Bheki Mabaso', 'email' => 'bheki@mabaso.test'],
            ['role' => 'provider', 'name' => 'Thandi Owner', 'email' => 'owner@tym.test'],
        ],
        ...$overrides,
    ]);
}

/** The signing link token sent to an email address. */
function linkFor(string $email): string
{
    $token = null;
    Notification::assertSentOnDemand(AgreementMail::class, function (AgreementMail $n, $channels, $notifiable) use ($email, &$token): bool {
        if (($notifiable->routes['mail'] ?? null) === $email && in_array($n->kind, ['invite', 'reminder', 'completed'], true)) {
            $token = basename($n->url);
        }

        return true;
    });

    return (string) $token;
}

function emailedCode(string $email): string
{
    $code = null;
    Notification::assertSentOnDemand(AgreementMail::class, function (AgreementMail $n, $channels, $notifiable) use ($email, &$code): bool {
        if (($notifiable->routes['mail'] ?? null) === $email && $n->kind === 'code') {
            $code = $n->code;
        }

        return true;
    });

    return (string) $code;
}

function signAs(object $test, string $token, string $name, string $code): TestResponse
{
    return $test->postJson(TYM_V1."/sign/{$token}", ['code' => $code, 'name' => $name, 'agree' => true]);
}

it('versions agreement wording and refuses unknown fields', function (): void {
    $this->postJson(TYM_V1.'/agreement-templates', ['kind' => 'learner', 'name' => 'Custom', 'parties' => ['learner', 'provider'],
        'body' => str_repeat('Words. ', 10).'{{learner.shoe_size}}'])->assertStatus(422)->assertJsonValidationErrors('body');

    $v1 = workplaceTemplate($this);
    $v2 = $this->patchJson(TYM_V1."/agreement-templates/{$v1}", ['name' => 'Workplace agreement 2027'])->assertOk()
        ->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft')->json('data.id');
    expect($v2)->not->toBe($v1);
    $this->postJson(TYM_V1."/agreement-templates/{$v2}/publish")->assertOk();
    $family = $this->getJson(TYM_V1.'/agreement-templates')->json('data.0');
    expect($family['published_version'])->toBe(2)->and(collect($family['versions'])->pluck('status')->all())->toBe(['published', 'retired']);
});

it('fills the agreement from the employer, workplace and qualification', function (): void {
    $t = workplaceTemplate($this);
    // Parties must match the template.
    sendWorkplace($this, $t, ['signers' => [['role' => 'learner', 'name' => 'Ayanda Zulu', 'phone' => '0821234567'], ['role' => 'provider', 'name' => 'T O', 'email' => 'o@t.test']]])
        ->assertStatus(422)->assertJsonValidationErrors('signers');
    // Every field the wording uses must have a value.
    sendWorkplace($this, $t, ['mentor_id' => null])->assertStatus(422)->assertJsonValidationErrors('template_id');

    $a = sendWorkplace($this, $t)->assertCreated()->json('data');
    expect($a['status'])->toBe('sent')->and($a['body'])->toContain('Mabaso Builders (Pty) Ltd (2015/123456/07)')
        ->toContain('Occupational Certificate: Bricklayer (SAQA 93627)')->toContain('1 February 2027 to 30 November 2027')
        ->not->toContain('{{')
        ->and($a['text_sha256'])->toBe(hash('sha256', $a['body']));

    // The learner's link goes by WhatsApp/SMS; the others by email.
    Notification::assertSentOnDemandTimes(AgreementMail::class, 2);
});

it('refuses a workplace that is not approved for the qualification', function (): void {
    $t = workplaceTemplate($this);
    asTenant($this->tym, fn () => $this->site->forceFill(['approval_expires_on' => now()->subDay()])->save());
    sendWorkplace($this, $t)->assertStatus(422)->assertJsonValidationErrors('site_id');
});

it('signs with a PIN and seals a PDF anyone can verify', function (): void {
    $t = workplaceTemplate($this);
    $agreement = sendWorkplace($this, $t)->json('data');
    $this->flushHeaders()->flushSession()->withHeaders(webOrigin('tym.skills.test')); // signers have no account

    // The employer signs with an emailed PIN.
    $token = linkFor('bheki@mabaso.test');
    $this->getJson(TYM_V1."/sign/{$token}")->assertOk()->assertJsonPath('data.you.name', 'Bheki Mabaso')->assertJsonPath('data.you.pin_to', 'b•••@mabaso.test');
    signAs($this, $token, 'Bheki Mabaso', '000000')->assertStatus(422)->assertJsonValidationErrors('code'); // no PIN asked for yet
    $this->postJson(TYM_V1."/sign/{$token}/code")->assertOk()->assertJsonPath('data.channel', 'email');
    $this->postJson(TYM_V1."/sign/{$token}/code")->assertStatus(422); // too soon for another
    $code = emailedCode('bheki@mabaso.test');
    signAs($this, $token, 'Bheki Mabaso', $code === '000000' ? '111111' : '000000')->assertStatus(422)->assertJsonValidationErrors('code');
    signAs($this, $token, 'Someone Else', $code)->assertStatus(422)->assertJsonValidationErrors('name');
    signAs($this, $token, '  bheki   mabaso ', $code)->assertOk()->assertJsonPath('data.you.status', 'signed');
    signAs($this, $token, 'Bheki Mabaso', $code)->assertStatus(422); // once only

    // The learner signs with a PIN by WhatsApp (their link went by phone; reissue it by email for the test).
    $learner = asTenant($this->tym, fn () => Signer::query()->where('role', 'learner')->firstOrFail());
    asTenant($this->tym, fn () => $learner->forceFill(['email' => 'ayanda@learner.test'])->save());
    signInOwner($this, $this->tym, 'owner2@tym.test');
    $this->postJson(TYM_V1."/agreements/{$agreement['id']}/signers/{$learner->id}/resend")->assertOk();
    $this->flushHeaders()->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $learnerToken = linkFor('ayanda@learner.test');
    $this->postJson(TYM_V1."/sign/{$learnerToken}/code")->assertOk()->assertJsonPath('data.channel', 'whatsapp')->assertJsonPath('data.destination', '+27 82 ••• 4567');
    preg_match('/^\d{6}/', (string) end(LogOtpChannel::$sent)['message'], $m);
    $pin = $m[0];
    signAs($this, $learnerToken, 'Ayanda Zulu', $pin)->assertOk();

    // The provider signs last: the agreement completes and is sealed.
    $providerToken = linkFor('owner@tym.test');
    $this->postJson(TYM_V1."/sign/{$providerToken}/code")->assertOk();
    signAs($this, $providerToken, 'Thandi Owner', emailedCode('owner@tym.test'))->assertOk()->assertJsonPath('data.agreement.status', 'completed')
        ->assertJsonPath('data.agreement.has_document', true);

    $sealed = asTenant($this->tym, fn () => Agreement::query()->findOrFail($agreement['id']));
    // Completion sends each signer a fresh link to their own copy; the old one stops working.
    $this->getJson(TYM_V1."/sign/{$providerToken}")->assertNotFound();
    $copy = linkFor('owner@tym.test');
    $pdf = $this->get(TYM_V1."/sign/{$copy}/document")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    expect(substr((string) $pdf, 0, 4))->toBe('%PDF')->and(hash('sha256', (string) $pdf))->toBe($sealed->sealed_sha256);

    // Public verification: who signed and when, and the fingerprints.
    $v = $this->getJson(TYM_V1."/verify/{$sealed->verify_code}")->assertOk()->json('data');
    expect($v['status'])->toBe('completed')->and($v['document_sha256'])->toBe($sealed->sealed_sha256)
        ->and(collect($v['signers'])->pluck('status')->unique()->all())->toBe(['signed']);
    // Everyone is told, with a link to their own copy.
    Notification::assertSentOnDemand(AgreementMail::class, fn (AgreementMail $n) => $n->kind === 'completed');
    // Evidence is kept per signature.
    $evidence = asTenant($this->tym, fn () => Signer::query()->where('role', 'employer')->firstOrFail()->evidence);
    expect($evidence)->toMatchArray(['channel' => 'email', 'method' => 'one-time PIN', 'document_sha256' => $sealed->body_sha256]);
});

it('stops after a decline, and locks out repeated wrong PINs', function (): void {
    sendWorkplace($this, workplaceTemplate($this));
    $this->flushHeaders()->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $token = linkFor('bheki@mabaso.test');
    $this->postJson(TYM_V1."/sign/{$token}/code")->assertOk();
    $wrong = emailedCode('bheki@mabaso.test') === '999999' ? '888888' : '999999';
    foreach (range(1, 5) as $_) {
        signAs($this, $token, 'Bheki Mabaso', $wrong)->assertStatus(422);
    }
    signAs($this, $token, 'Bheki Mabaso', emailedCode('bheki@mabaso.test'))->assertStatus(422)->assertJsonPath('errors.code.0', 'Too many wrong PINs. Ask for a new one.');

    $this->postJson(TYM_V1."/sign/{$token}/decline", ['reason' => 'The end date is wrong.'])->assertOk()->assertJsonPath('data.agreement.status', 'declined');
    $this->postJson(TYM_V1."/sign/{$token}/code")->assertStatus(422);
});

it('reminds on day 3 and day 7, then expires', function (): void {
    sendWorkplace($this, workplaceTemplate($this));
    $count = fn () => collect(Notification::sentNotifications())->flatten(2)->count();
    $this->travel(3)->days();
    Artisan::call('agreements:follow-up');
    Notification::assertSentOnDemand(AgreementMail::class, fn (AgreementMail $n) => $n->kind === 'reminder');
    $after3 = $count();
    Artisan::call('agreements:follow-up');
    expect($count())->toBe($after3);
    $this->travel(28)->days();
    Artisan::call('agreements:follow-up');
    expect(asTenant($this->tym, fn () => Agreement::query()->firstOrFail()->status))->toBe('expired');
});

it('uses LAWtrust for advanced signatures, only when connected', function (): void {
    $t = workplaceTemplate($this, advanced: true);
    sendWorkplace($this, $t)->assertStatus(422)->assertJsonPath('errors.template_id.0', fn ($m) => str_contains($m, 'LAWtrust is not connected'));

    asPlatform(fn () => Integration::query()->create(['type' => 'esign', 'driver' => 'esign.lawtrust', 'enabled' => true, 'mode' => 'sandbox',
        'credentials' => ['client_secret' => 's', 'password' => 'p'], 'settings' => ['base_url' => 'https://hub.lawtrust.test', 'client_id' => 'c', 'username' => 'api@jv.test']]));
    // Advanced signatures go by email: every signer needs one.
    sendWorkplace($this, $t)->assertStatus(422)->assertJsonValidationErrors('signers.0.email');

    Http::fake([
        'hub.lawtrust.test/authenticate' => Http::response(['access_token' => 'tok']),
        'hub.lawtrust.test/v4/packages' => Http::response(['package_id' => 4711]),
        'hub.lawtrust.test/v4/packages/4711/documents' => Http::response(['documentid' => 9]),
        'hub.lawtrust.test/v4/packages/4711/workflow/users' => Http::response([]),
        'hub.lawtrust.test/v4/packages/4711/workflow' => Http::response([]),
        'hub.lawtrust.test/v4/packages/4711' => Http::sequence()->push(['package_status' => 'IN_PROGRESS'])
            ->push(['package_status' => 'COMPLETED', 'documents' => [['document_id' => 9]]]),
        'hub.lawtrust.test/v4/packages/4711/documents/9' => Http::response('%PDF-1.7 signed by LAWtrust', 200, ['Content-Type' => 'application/pdf']),
    ]);
    $signers = [['role' => 'learner', 'name' => 'Ayanda Zulu', 'email' => 'ayanda@learner.test'], ['role' => 'employer', 'name' => 'Bheki Mabaso', 'email' => 'bheki@mabaso.test'],
        ['role' => 'provider', 'name' => 'Thandi Owner', 'email' => 'owner@tym.test']];
    $id = sendWorkplace($this, $t, ['signers' => $signers])->assertCreated()->assertJsonPath('data.signature_level', 'advanced')->json('data.id');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/workflow/users') && count($r->data()) === 3 && $r->data()[0]['user_email'] === 'ayanda@learner.test');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/4711/documents') && str_starts_with($r->body(), '%PDF'));
    Notification::assertNothingSent(); // SigningHub emails the signers itself

    Artisan::call('agreements:follow-up', ['--advanced' => true]);
    expect(asTenant($this->tym, fn () => Agreement::query()->findOrFail($id)->status))->toBe('sent');
    Artisan::call('agreements:follow-up', ['--advanced' => true]);
    $done = asTenant($this->tym, fn () => Agreement::query()->findOrFail($id));
    expect($done->status)->toBe('completed')->and($done->sealed_sha256)->toBe(hash('sha256', '%PDF-1.7 signed by LAWtrust'));
});

it('keeps agreements inside the provider', function (): void {
    sendWorkplace($this, workplaceTemplate($this));
    $token = linkFor('bheki@mabaso.test');
    $other = makeProvider('other');
    expect(asTenant($other, fn () => Agreement::query()->count()))->toBe(0);
    $this->flushHeaders()->flushSession()->withHeaders(webOrigin('other.skills.test'));
    $this->getJson("https://other.skills.test/v1/sign/{$token}")->assertNotFound();

    makeMember($this->tym, ['learner'], ['email' => 'learner@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'learner@tym.test');
    $this->getJson(TYM_V1.'/agreements')->assertForbidden();
    $this->postJson(TYM_V1.'/agreement-templates', ['starter' => 'workplace'])->assertForbidden();
});
