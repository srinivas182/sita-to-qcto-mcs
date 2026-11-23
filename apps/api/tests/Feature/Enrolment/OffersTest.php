<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Modules\Access\Services\AccessService;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Services\AgreementService;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\Offer;
use Modules\Enrolment\Services\ApplicationService;
use Modules\Identity\Models\Consent;
use Modules\Identity\Models\User;

beforeEach(function (): void {
    enrolmentSetup($this);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null, 'places' => 2]);
});

/** Publish the standard learner agreement as the owner (staying signed in). */
function learnerTemplate(object $test): void
{
    signInOwner($test, $test->tym, 'owner2@tym.test');
    $id = $test->postJson(TYM_V1.'/agreement-templates', ['starter' => 'learner'])->assertCreated()->json('data.id');
    $test->postJson(TYM_V1."/agreement-templates/{$id}/publish")->assertOk();
}

function applyAs(object $test, string $phone, string $id, string $first = 'Ayanda'): Application
{
    $test->flushHeaders()->flushSession();
    $ref = applyPublic($test, $test->intake, ['id_number' => $id, 'first_name' => $first], $phone)->assertCreated()->json('data.reference');
    $test->travel(1)->minutes();

    return asTenant($test->tym, fn () => Application::query()->where('reference', $ref)->sole());
}

/** Everyone signs the learner agreement (the signing flow itself is tested in Agreements). */
function completeAgreement(object $test, string $agreementId): void
{
    asTenant($test->tym, function () use ($agreementId): void {
        Signer::query()->where('agreement_id', $agreementId)->update(['status' => 'signed', 'signed_at' => now(), 'signed_name' => 'Signed']);
        app(AgreementService::class)->complete(Agreement::query()->findOrFail($agreementId));
    });
}

it('makes an offer only with a learner agreement published, documents in and a place free', function (): void {
    $a = applyAs($this, '082 123 4567', '9202204720083');
    signInOwner($this, $this->tym, 'staff@tym.test');
    $this->postJson(TYM_V1."/learner-applications/{$a->id}/offer")->assertStatus(422)->assertJsonValidationErrors('agreement');

    learnerTemplate($this);
    $offer = $this->postJson(TYM_V1."/learner-applications/{$a->id}/offer", ['days' => 5, 'funding' => 'seta_grant'])->assertCreated()->json('data');
    expect($offer['status'])->toBe('open')->and($offer['link'])->toContain('/offer/')->and($offer['needs_guardian'])->toBeFalse()
        ->and($offer['consent']['text'])->toContain('Training Young Minds')->and($offer['course']['cohort'])->toBe('Bricklayer February 2027');
    expect(asTenant($this->tym, fn () => $a->refresh()->status))->toBe('offered');

    // Two places: a second offer fits, a third does not.
    $b = applyAs($this, '082 222 3333', '9001014800089', 'Kagiso');
    $c = applyAs($this, '082 444 5555', '9505055800089', 'Nomsa');
    signInOwner($this, $this->tym, 'staff2@tym.test');
    $this->postJson(TYM_V1."/learner-applications/{$b->id}/offer")->assertCreated();
    $this->postJson(TYM_V1."/learner-applications/{$c->id}/offer")->assertStatus(422)->assertJsonValidationErrors('places');
});

it('refuses an offer while required documents are outstanding', function (): void {
    asTenant($this->tym, fn () => $this->intake->forceFill(['document_types' => ['id_document']])->save());
    $a = applyAs($this, '082 123 4567', '9202204720083');
    learnerTemplate($this);
    $this->postJson(TYM_V1."/learner-applications/{$a->id}/offer")->assertStatus(422)->assertJsonValidationErrors('documents');
});

it('accepts with consent, sends the agreement, and enrols once it is signed', function (): void {
    $a = applyAs($this, '082 123 4567', '9202204720083');
    learnerTemplate($this);
    $made = $this->postJson(TYM_V1."/learner-applications/{$a->id}/offer", ['funding' => 'employer'])->assertCreated()->json('data');
    $token = substr((string) $made['link'], -48);
    $this->flushHeaders()->flushSession();

    $this->getJson(TYM_V1."/public/offers/{$token}")->assertOk()->assertJsonPath('data.learner', 'Ayanda');
    $this->postJson(TYM_V1."/public/offers/{$token}/accept", ['consent_version' => 1])->assertStatus(422)->assertJsonValidationErrors('consent');
    $this->postJson(TYM_V1."/public/offers/{$token}/accept", ['consent' => true, 'consent_version' => 9])->assertStatus(422)->assertJsonValidationErrors('consent_version');
    $accepted = $this->postJson(TYM_V1."/public/offers/{$token}/accept", ['consent' => true, 'consent_version' => 1])->assertOk()->json('data');
    expect($accepted['status'])->toBe('accepted')->and($accepted['agreement_id'])->not->toBeNull();

    $learner = asPlatform(fn () => User::query()->where('phone_e164', '+27821234567')->sole());
    asTenant($this->tym, function () use ($learner, $accepted): void {
        $consent = Consent::query()->where('user_id', $learner->id)->sole();
        expect($consent->version)->toBe('enrolment-v1')->and($consent->channel)->toBe('link')->and($consent->consent_text_id)->not->toBeNull();
        expect(Signer::query()->where('agreement_id', $accepted['agreement_id'])->pluck('role')->sort()->values()->all())->toBe(['learner', 'provider']);
        expect(Enrolment::query()->count())->toBe(0); // not until signed
    });
    $this->postJson(TYM_V1."/public/offers/{$token}/accept", ['consent' => true, 'consent_version' => 1])->assertStatus(422); // only once

    completeAgreement($this, $accepted['agreement_id']);
    asTenant($this->tym, function () use ($learner, $a): void {
        $e = Enrolment::query()->sole();
        expect($e->user_id)->toBe($learner->id)->and($e->funding)->toBe('employer')->and($e->status)->toBe('active')
            ->and($a->refresh()->status)->toBe('enrolled')
            ->and(app(AccessService::class)->roles($learner))->toContain('learner');
    });

    // The learner sees the course; the provider sees the enrolment and readiness counts it.
    asPlatform(fn () => $learner->forceFill(['email' => 'ayanda@learner.test', 'password' => 'correct horse battery staple'])->save());
    signInAs($this, 'ayanda@learner.test');
    $this->getJson(TYM_V1.'/me/courses')->assertOk()->assertJsonPath('data.0.cohort', 'Bricklayer February 2027')->assertJsonPath('data.0.status', 'active');
    signInOwner($this, $this->tym, 'viewer@tym.test');
    $this->getJson(TYM_V1.'/enrolments?cohort_id='.$this->cohort->id)->assertOk()->assertJsonPath('data.0.learner.name', 'Ayanda Zulu');
    $items = collect($this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/readiness")->json('data.items'));
    expect($items->firstWhere('key', 'learners.capacity')['status'] ?? null)->toBe('pass');
});

it('needs a parent or guardian for a learner under 18, who signs too', function (): void {
    $a = applyAs($this, '082 123 4567', '1203155800088');
    learnerTemplate($this);
    $token = substr((string) $this->postJson(TYM_V1."/learner-applications/{$a->id}/offer")->assertCreated()->json('data.link'), -48);
    $this->flushHeaders()->flushSession();

    $this->getJson(TYM_V1."/public/offers/{$token}")->assertJsonPath('data.needs_guardian', true);
    $this->postJson(TYM_V1."/public/offers/{$token}/accept", ['consent' => true, 'consent_version' => 1])->assertStatus(422)->assertJsonValidationErrors('guardian');
    $this->postJson(TYM_V1."/public/offers/{$token}/accept", ['consent' => true, 'consent_version' => 1,
        'guardian' => ['name' => 'Thandi Zulu', 'phone' => '082 123 4567', 'relationship' => 'Mother']])->assertStatus(422)->assertJsonValidationErrors('guardian.phone');
    $id = $this->postJson(TYM_V1."/public/offers/{$token}/accept", ['consent' => true, 'consent_version' => 1,
        'guardian' => ['name' => 'Thandi Zulu', 'phone' => '083 765 4321', 'relationship' => 'Mother']])->assertOk()->json('data.agreement_id');

    asTenant($this->tym, function () use ($id): void {
        $signers = Signer::query()->where('agreement_id', $id)->orderBy('sort')->get();
        expect($signers->pluck('role')->all())->toBe(['learner', 'provider', 'guardian'])->and($signers->last()->phone)->toBe('+27837654321');
        expect(Consent::query()->sole()->given_by)->toBe('Thandi Zulu (Mother)');
    });
});

it('lapses late offers and moves the waiting list up', function (): void {
    $a = applyAs($this, '082 123 4567', '9202204720083');
    $b = applyAs($this, '082 222 3333', '9001014800089', 'Kagiso');
    $w = applyAs($this, '082 444 5555', '9505055800089', 'Nomsa');
    learnerTemplate($this);
    $this->postJson(TYM_V1."/learner-applications/{$a->id}/offer", ['days' => 1])->assertCreated();
    $this->postJson(TYM_V1."/learner-applications/{$b->id}/offer", ['days' => 10])->assertCreated();
    $this->postJson(TYM_V1."/learner-applications/{$w->id}/waitlist")->assertOk();

    $this->travel(3)->days();
    $this->artisan('enrolment:lapse-offers')->assertSuccessful();
    asTenant($this->tym, function () use ($a, $w): void {
        expect($a->refresh()->status)->toBe('declined')->and($w->refresh()->status)->toBe('offered')
            ->and(Offer::query()->where('application_id', $a->id)->value('status'))->toBe('lapsed');
    });
});

it('frees the place when an applicant declines or withdraws', function (): void {
    $a = applyAs($this, '082 123 4567', '9202204720083');
    $w = applyAs($this, '082 444 5555', '9505055800089', 'Nomsa');
    learnerTemplate($this);
    asTenant($this->tym, fn () => $this->intake->forceFill(['places' => 1])->save());
    $token = substr((string) $this->postJson(TYM_V1."/learner-applications/{$a->id}/offer")->assertCreated()->json('data.link'), -48);
    $this->postJson(TYM_V1."/learner-applications/{$w->id}/waitlist")->assertOk();

    $this->flushHeaders()->flushSession();
    $this->postJson(TYM_V1."/public/offers/{$token}/decline", ['reason' => 'Found work'])->assertOk()->assertJsonPath('data.status', 'declined');
    asTenant($this->tym, fn () => expect($w->refresh()->status)->toBe('offered'));

    // Withdrawing an application with an open offer closes the offer.
    $w2 = asTenant($this->tym, fn () => $w->refresh());
    asTenant($this->tym, fn () => app(ApplicationService::class)->withdraw($w2));
    asTenant($this->tym, fn () => expect(Offer::query()->where('application_id', $w->id)->value('status'))->toBe('withdrawn'));
});

it('versions the consent wording when the provider changes it', function (): void {
    signInOwner($this, $this->tym, 'w@tym.test');
    $this->getJson(TYM_V1.'/consent-text')->assertOk()->assertJsonPath('data.version', 1);
    $this->putJson(TYM_V1.'/consent-text', ['text' => str_repeat('We use your details to enrol and report you. ', 5)])->assertOk()->assertJsonPath('data.version', 2);
    $this->putJson(TYM_V1.'/consent-text', ['text' => str_repeat('We use your details to enrol and report you. ', 5)])->assertOk()->assertJsonPath('data.version', 2); // unchanged
});
