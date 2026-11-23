<?php

declare(strict_types=1);

require_once __DIR__.'/../People/helpers.php';

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\Agreements\Models\Signer;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\Placement;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Models\Device;
use Modules\Identity\Otp\LogOtpChannel;
use Modules\Tenancy\Models\Centre;
use Tests\Support\TestDevice;

beforeEach(function (): void {
    Notification::fake();
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'r1']]])]);
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds', 'legal_name' => 'Training Young Minds (Pty) Ltd']);
    $this->q = catalogueEntry();
    $this->ayanda = makeMember($this->tym, ['learner'], ['phone_e164' => '+27821234567', 'first_name' => 'Ayanda', 'last_name' => 'Zulu']);
});

/** Sign in on a phone (PIN by WhatsApp) and return the app's bearer header. */
function phone(object $test, string $local = '0821234567'): array
{
    $challenge = $test->postJson(TYM_V1.'/auth/otp/request', ['identifier' => $local])->json('challenge_id');
    preg_match('/^(\d{6})/', end(LogOtpChannel::$sent)['message'], $m);
    $token = $test->postJson(TYM_V1.'/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $m[1], 'device' => (new TestDevice)->payload()])->assertOk()->json('access_token');

    return ['Authorization' => "Bearer {$token}"];
}

/** Publish the learner agreement and send one to each phone number, as the owner. */
function sendLearnerAgreement(object $test, string ...$phones): void
{
    signInOwner($test, $test->tym);
    $id = $test->postJson(TYM_V1.'/agreement-templates', ['starter' => 'learner'])->json('data.id');
    $test->postJson(TYM_V1."/agreement-templates/{$id}/publish")->assertOk();
    foreach ($phones ?: ['082 123 4567'] as $phone) {
        $test->postJson(TYM_V1.'/agreements', ['template_id' => $id, 'qualification_id' => $test->q->id, 'start_date' => '2027-02-01', 'end_date' => '2027-12-01',
            'signers' => [['role' => 'learner', 'name' => 'Ayanda Zulu', 'phone' => $phone], ['role' => 'provider', 'name' => 'Thandi Owner', 'email' => 'owner@tym.test']]])->assertCreated();
    }
    $test->flushHeaders()->flushSession();
}

it('links the learner by phone, pushes a notice, and signs in the app with a PIN', function (): void {
    $app = phone($this);
    $this->postJson(TYM_V1.'/me/push-tokens', ['token' => 'ExponentPushToken[abcdefghijklmnop]'], $app)->assertOk();
    $this->flushHeaders();
    sendLearnerAgreement($this);

    $signer = asTenant($this->tym, fn () => Signer::query()->where('role', 'learner')->sole());
    expect($signer->user_id)->toBe($this->ayanda->id);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'exp.host') && str_contains($r->body(), 'You have a document to sign') && str_contains($r->body(), $signer->id));

    $list = $this->getJson(TYM_V1.'/me/agreements', $app)->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0])->toMatchArray(['id' => $signer->id, 'can_sign' => true, 'status' => 'pending']);
    $this->getJson(TYM_V1."/me/agreements/{$signer->id}", $app)->assertOk()->assertJsonPath('data.you.pin_to', '+27 82 ••• 4567')
        ->assertJsonPath('data.body', fn ($b) => str_contains($b, 'Ayanda Zulu'));

    $this->postJson(TYM_V1."/me/agreements/{$signer->id}/code", [], $app)->assertOk()->assertJsonPath('data.channel', 'whatsapp');
    preg_match('/^(\d{6})/', end(LogOtpChannel::$sent)['message'], $m);
    $this->postJson(TYM_V1."/me/agreements/{$signer->id}/sign", ['code' => $m[1], 'name' => 'Ayanda Zulu', 'agree' => true], $app)
        ->assertOk()->assertJsonPath('data.you.status', 'signed')->assertJsonPath('data.can_sign', false);

    $evidence = asTenant($this->tym, fn () => $signer->refresh()->evidence);
    $device = asTenant($this->tym, fn () => Device::query()->sole()->id);
    expect($evidence)->toMatchArray(['via' => 'app', 'device' => $device, 'method' => 'one-time PIN']);
});

it('shows people only their own documents, and never guesses who a signer is', function (): void {
    makeMember($this->tym, ['learner'], ['phone_e164' => '+27829990000', 'first_name' => 'Other', 'last_name' => 'Learner']);
    sendLearnerAgreement($this, '071 000 0000', '082 123 4567'); // nobody here has the first number; the second is Ayanda's
    expect(asTenant($this->tym, fn () => Signer::query()->where('role', 'learner')->whereNull('user_id')->count()))->toBe(1);
    $mine = asTenant($this->tym, fn () => Signer::query()->where('user_id', $this->ayanda->id)->sole());
    $other = phone($this, '0829990000');
    $this->getJson(TYM_V1.'/me/agreements', $other)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(TYM_V1."/me/agreements/{$mine->id}", $other)->assertNotFound();
    $this->postJson(TYM_V1."/me/agreements/{$mine->id}/code", [], $other)->assertNotFound();
});

it('shows a mentor the workplaces they mentor at and the cohorts planned there', function (): void {
    $bheki = makeMember($this->tym, ['host-employer'], ['phone_e164' => '+27825550101', 'first_name' => 'Bheki', 'last_name' => 'Mabaso']);
    asTenant($this->tym, function () use ($bheki): void {
        $e = Employer::query()->create(['legal_name' => 'Mabaso Builders (Pty) Ltd']);
        $s = EmployerSite::query()->create(['employer_id' => $e->id, 'name' => 'Soweto yard', 'city' => 'Soweto', 'approval_number' => 'W-1', 'approval_body' => 'CETA',
            'approval_expires_on' => now()->addYear(), 'approved_qualification_ids' => [$this->q->id]]);
        Mentor::query()->create(['employer_id' => $e->id, 'user_id' => $bheki->id, 'name' => 'Bheki Mabaso', 'designation' => 'Foreman', 'years_experience' => 14, 'site_ids' => [$s->id]]);
        $centre = Centre::query()->create(['name' => 'Soweto Campus']);
        $c = Cohort::query()->create(['name' => 'Bricklayer February 2027', 'qualification_id' => $this->q->id, 'centre_id' => $centre->id, 'mode' => 'contact',
            'intake_on' => now()->addMonth(), 'planned_end_on' => now()->addYear(), 'capacity' => 8]);
        $c->forceFill(['status' => 'open'])->save();
        Placement::query()->create(['cohort_id' => $c->id, 'employer_id' => $e->id, 'site_id' => $s->id, 'planned_learners' => 8]);
    });

    $app = phone($this, '0825550101');
    $data = $this->getJson(TYM_V1.'/me/mentoring', $app)->assertOk()->json('data');
    expect($data['is_mentor'])->toBeTrue()->and($data['workplaces'][0])->toMatchArray(['name' => 'Soweto yard', 'employer' => 'Mabaso Builders (Pty) Ltd'])
        ->and($data['workplaces'][0]['cohorts'][0])->toMatchArray(['name' => 'Bricklayer February 2027', 'learners' => 8, 'status' => 'open']);

    $this->getJson(TYM_V1.'/me/mentoring', phone($this))->assertOk()->assertJsonPath('data.is_mentor', false);
});
