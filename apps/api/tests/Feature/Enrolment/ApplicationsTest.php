<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Carbon\CarbonImmutable;
use Modules\Access\Services\AccessService;
use Modules\Billing\Services\LearnerMeter;
use Modules\Enrolment\Models\Application;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\IdType;

beforeEach(function (): void {
    enrolmentSetup($this);
});

it('publishes an intake only while the qualification takes enrolments', function (): void {
    asPlatform(fn () => $this->q->forceFill(['last_enrolment_on' => now()->addMonth()])->save()); // closes before the intake date
    signInOwner($this, $this->tym);
    $id = $this->postJson(TYM_V1.'/intakes', ['cohort_id' => $this->cohort->id, 'title' => 'Bricklayer', 'opens_on' => now()->toDateString(), 'closes_on' => now()->addWeeks(2)->toDateString()])
        ->assertCreated()->assertJsonPath('data.places', 20)->assertJsonPath('data.status', 'draft')->json('data.id');
    $this->postJson(TYM_V1."/intakes/{$id}/publish")->assertStatus(422)->assertJsonValidationErrors('status');

    asPlatform(fn () => $this->q->forceFill(['last_enrolment_on' => null])->save());
    $this->postJson(TYM_V1."/intakes/{$id}/publish")->assertOk()->assertJsonPath('data.accepting', true)->assertJsonPath('data.url', fn ($u) => str_contains($u, '/join/'));
    $this->postJson(TYM_V1.'/intakes', ['cohort_id' => $this->cohort->id, 'title' => 'Late', 'opens_on' => now()->toDateString(), 'closes_on' => now()->addYears(2)->toDateString()])
        ->assertStatus(422)->assertJsonValidationErrors('closes_on');
});

it('lets someone apply with a phone PIN, without becoming a billed learner', function (): void {
    $intake = openIntake($this);
    $this->getJson(TYM_V1."/public/intakes/{$intake->slug}")->assertOk()
        ->assertJsonPath('data.qualification.saqa_id', '93627')->assertJsonPath('data.centre.city', 'Soweto')->assertJsonPath('data.provider', 'Training Young Minds');
    $this->getJson(TYM_V1.'/public/intakes')->assertOk()->assertJsonCount(1, 'data');

    // A wrong PIN, then the right one.
    $challenge = $this->postJson(TYM_V1."/public/intakes/{$intake->slug}/code", ['phone' => '0821234567'])->json('data.challenge_id');
    $this->postJson(TYM_V1."/public/intakes/{$intake->slug}/apply", ['challenge_id' => $challenge, 'code' => '000000', 'first_name' => 'A', 'last_name' => 'Z',
        'id_type' => 'sa_id', 'id_number' => '9202204720083', 'answers' => ['employed' => true]])->assertStatus(422)->assertJsonValidationErrors('code');

    $this->travel(1)->minutes();
    $ref = applyPublic($this, $intake)->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.reference');
    expect($ref)->toStartWith('APP-');

    $user = asPlatform(fn () => User::query()->where('phone_e164', '+27821234567')->sole());
    expect($user->id_number)->toBe('9202204720083')->and($user->dob?->toDateString())->toBe('1992-02-20');
    expect(asTenant($this->tym, fn () => UserTenant::query()->where('user_id', $user->id)->sole()->status->value))->toBe('active')
        ->and(asTenant($this->tym, fn () => app(AccessService::class)->roles($user)))->toBe([])
        ->and(app(LearnerMeter::class)->activeLearners($this->tym))->toBe(0);

    // Once per intake.
    $this->travel(1)->minutes();
    applyPublic($this, $intake)->assertStatus(422)->assertJsonValidationErrors('application');
});

it('checks answers, age and identity before accepting an application', function (): void {
    $intake = openIntake($this);
    applyPublic($this, $intake, ['answers' => ['transport' => 'Helicopter']])->assertStatus(422)->assertJsonValidationErrors(['answers.employed', 'answers.transport']);
    $this->travel(1)->minutes();
    applyPublic($this, $intake, ['id_number' => '9202204720084'])->assertStatus(422)->assertJsonValidationErrors('id_number'); // bad checksum
    // Born 2012: under the intake's minimum age of 18.
    applyPublic($this, $intake, ['id_number' => '1203155800088'], '0831112222')->assertStatus(422)->assertJsonPath('errors.id_number.0', 'Applicants must be at least 18.');

    // An ID number registered to someone with another phone is refused, never merged.
    $this->travel(1)->minutes();
    applyPublic($this, $intake)->assertCreated();
    applyPublic($this, $intake, [], '0839998888')->assertStatus(422)->assertJsonPath('errors.id_number.0', fn ($m) => str_contains($m, 'another mobile number'));
    $this->getJson(TYM_V1.'/public/intakes/'.$intake->slug)->assertOk();
});

it('moves applications along the board, with a waiting list', function (): void {
    $intake = openIntake($this);
    applyPublic($this, $intake)->assertCreated();
    applyPublic($this, $intake, ['first_name' => 'Lerato', 'last_name' => 'Nkosi', 'id_number' => '9001014800089'], '0827776666')->assertCreated();

    signInOwner($this, $this->tym, 'owner2@tym.test');
    $board = $this->getJson(TYM_V1."/applications?intake_id={$intake->id}")->assertOk()->json();
    expect($board['data'])->toHaveCount(2)->and($board['places'])->toBe(['total' => 20, 'taken' => 0])
        ->and($board['data'][0]['applicant'])->toMatchArray(['name' => 'Ayanda Zulu', 'id_number' => '920220•••••83'])
        ->and($board['data'][0]['answers'])->toBe(['employed' => false, 'transport' => 'Taxi']);
    [$a, $b] = array_column($board['data'], 'id');

    $this->postJson(TYM_V1."/applications/{$a}/shortlist")->assertOk()->assertJsonPath('data.status', 'shortlisted');
    $this->postJson(TYM_V1."/applications/{$b}/waitlist")->assertOk()->assertJsonPath('data.waitlist_position', 1);
    $this->postJson(TYM_V1."/applications/{$a}/decline")->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson(TYM_V1."/applications/{$a}/decline", ['reason' => 'No matric certificate'])->assertOk()->assertJsonPath('data.status', 'declined');
    $this->postJson(TYM_V1."/applications/{$a}/waitlist")->assertStatus(422);
    $this->postJson(TYM_V1."/applications/{$a}/reopen")->assertOk()->assertJsonPath('data.status', 'submitted');
});

it('lets members apply and withdraw in the app, and keeps applications private', function (): void {
    $intake = openIntake($this);
    $me = makeMember($this->tym, [], ['email' => 'sipho@tym.test', 'password' => 'correct horse battery staple', 'phone_e164' => '+27825550000']);
    signInAs($this, 'sipho@tym.test');
    $this->postJson(TYM_V1.'/me/applications', ['intake_id' => $intake->id, 'answers' => ['employed' => true]])->assertStatus(422)->assertJsonValidationErrors('id_number');
    asPlatform(function () use ($me): void {
        $me->setIdentityNumber(IdType::SaId, '9001014800089');
        $me->dob = now()->setDate(1990, 1, 1);
        $me->save();
    });
    $id = $this->postJson(TYM_V1.'/me/applications', ['intake_id' => $intake->id, 'answers' => ['employed' => true]])->assertCreated()->json('data.id');
    $this->getJson(TYM_V1.'/me/applications')->assertOk()->assertJsonPath('data.0.intake', 'Bricklayer, February 2027');
    $this->postJson(TYM_V1."/me/applications/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');

    // Not visible to people without application rights, nor to another provider.
    $this->getJson(TYM_V1."/applications?intake_id={$intake->id}")->assertForbidden();
    expect(asTenant(makeProvider('other'), fn () => Application::query()->count()))->toBe(0);
    makeMember($this->tym, ['host-employer'], ['email' => 'host@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'host@tym.test');
    $this->getJson(TYM_V1.'/intakes')->assertForbidden();
});

it('stops taking applications when the intake closes', function (): void {
    $intake = openIntake($this);
    signInOwner($this, $this->tym, 'owner2@tym.test');
    $this->postJson(TYM_V1."/intakes/{$intake->id}/close")->assertOk()->assertJsonPath('data.accepting', false);
    $this->flushHeaders()->flushSession();
    $this->postJson(TYM_V1."/public/intakes/{$intake->slug}/code", ['phone' => '0821234567'])->assertNotFound();
    $this->getJson(TYM_V1."/public/intakes/{$intake->slug}")->assertOk()->assertJsonPath('data.accepting', false);
});

it('limits PIN requests per phone, not per network', function (): void {
    $intake = openIntake($this);
    $this->postJson(TYM_V1."/public/intakes/{$intake->slug}/code", ['phone' => '0821234567'])->assertOk();
    $this->postJson(TYM_V1."/public/intakes/{$intake->slug}/code", ['phone' => '0821234567'])->assertStatus(422)->assertJsonValidationErrors('phone');
    // Others at the same open day are not held up.
    foreach (['0831110001', '0831110002', '0831110003', '0831110004', '0831110005', '0831110006'] as $phone) {
        $this->postJson(TYM_V1."/public/intakes/{$intake->slug}/code", ['phone' => $phone])->assertOk();
    }
});

it('shows ages in whole years', function (): void {
    $intake = openIntake($this);
    applyPublic($this, $intake)->assertCreated();
    signInOwner($this, $this->tym, 'owner3@tym.test');
    $age = $this->getJson(TYM_V1."/applications?intake_id={$intake->id}")->json('data.0.applicant.age');
    expect($age)->toBeInt()->toBe((int) floor(CarbonImmutable::parse('1992-02-20')->diffInYears(now())));
});
