<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Modules\Assessment\Models\Result;
use Modules\Enrolment\Models\Enrolment;
use Modules\People\Models\StaffCredential;
use Modules\Tenancy\Models\Centre;

beforeEach(fn () => assessmentSetup($this));

it('sets instruments per module, with methods that suit the module and a pass mark for tests', function (): void {
    $this->putJson(TYM_V1."/modules/{$this->km->id}/instruments", ['instruments' => [['method' => 'observation', 'title' => 'Watch', 'criteria' => [['text' => 'x y']]]]])
        ->assertStatus(422)->assertJsonValidationErrors('instruments.0.method');
    $this->putJson(TYM_V1."/modules/{$this->km->id}/instruments", ['instruments' => [['method' => 'test', 'title' => 'Test', 'criteria' => [['text' => 'Knows it']]]]])
        ->assertStatus(422)->assertJsonValidationErrors('instruments.0.pass_mark');
    $this->getJson(TYM_V1."/modules/{$this->pm->id}/instruments")->assertOk()->assertJsonPath('data.methods', ['observation', 'project', 'assignment'])
        ->assertJsonPath('data.instruments.0.criteria.1.key', 'bond');
    $this->getJson(TYM_V1.'/assessment-plan?qualification_id='.$this->q->id)->assertOk()->assertJsonPath('data.0.instruments', 1)->assertJsonPath('data.2.instruments', 0);
    $this->getJson(TYM_V1.'/assessment-settings')->assertOk()->assertJsonPath('data.max_attempts', 3)->assertJsonPath('data.random_pct', 25);
    $this->putJson(TYM_V1.'/assessment-settings', ['max_attempts' => 2])->assertOk()->assertJsonPath('data.max_attempts', 2);
});

it('records a registered assessor\'s decision that follows the criteria, and counts attempts', function (): void {
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, false, ['outcome' => 'competent']))->assertStatus(422)->assertJsonValidationErrors('outcome');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, false, ['feedback' => null]))->assertStatus(422)->assertJsonValidationErrors('feedback');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true, ['criteria' => [['key' => 'plumb', 'met' => true]]]))->assertStatus(422)->assertJsonValidationErrors('criteria');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true, ['assessed_on' => now()->addDays(2)->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('assessed_on');

    $this->getJson(TYM_V1.'/me/assessing')->assertOk()->assertJsonPath('data.0.id', $this->cohort->id);
    $first = $this->postJson(TYM_V1.'/assessment-results', observation($this, false, ['client_id' => '0b6f0c55-3a41-4d0f-9c53-5c1c1c1c1c1c']))->assertCreated()->json('data');
    expect($first)->toMatchArray(['attempt' => 1, 'outcome' => 'nyc', 'intact' => true]);
    $this->postJson(TYM_V1.'/assessment-results', observation($this, false, ['client_id' => '0b6f0c55-3a41-4d0f-9c53-5c1c1c1c1c1c']))->assertOk()->assertJsonPath('data.id', $first['id']); // retry
    $second = $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertCreated()->json('data');
    expect($second)->toMatchArray(['attempt' => 2, 'outcome' => 'competent']);
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertStatus(422)->assertJsonValidationErrors('instrument_id'); // already competent

    // Tests: the score decides.
    $test = ['enrolment_id' => $this->enrolment, 'instrument_id' => $this->kmTest['id'], 'assessed_on' => $this->today, 'criteria' => [['key' => 'k1', 'met' => true]]];
    $this->postJson(TYM_V1.'/assessment-results', $test + ['outcome' => 'competent', 'score' => 55])->assertStatus(422)->assertJsonValidationErrors('outcome');
    $this->postJson(TYM_V1.'/assessment-results', $test + ['outcome' => 'competent'])->assertStatus(422)->assertJsonValidationErrors('score');
    $this->postJson(TYM_V1.'/assessment-results', $test + ['outcome' => 'competent', 'score' => 72])->assertCreated()->assertJsonPath('data.score', 72);

    // The cohort grid and the learner's detail.
    $grid = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/results")->assertOk()->json('data');
    expect(collect($grid['learners'][0]['modules'])->pluck('state', 'module_id')->all())
        ->toBe([$this->km->id => 'competent', $this->pm->id => 'competent', $this->wm->id => 'no_assessment']);
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/results/{$this->enrolment}")->assertOk()->assertJsonCount(3, 'data.history')->assertJsonPath('data.modules.1.instruments.0.attempts', 2);

    // The learner sees outcomes and feedback, not who marked it or the fingerprint check.
    signInAs($this, 'ayanda@learner.test');
    $mine = $this->getJson(TYM_V1.'/me/results')->assertOk()->json('data.0.modules.1');
    expect($mine['state'])->toBe('competent')->and($mine['instruments'][0]['latest'])->not->toHaveKey('assessor_id')->and($mine['instruments'][0]['latest']['feedback'])->toBe('Neat work');
});

it('stops at the provider\'s attempt limit', function (): void {
    $this->putJson(TYM_V1.'/assessment-settings', ['max_attempts' => 2])->assertOk();
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, false))->assertCreated();
    $this->postJson(TYM_V1.'/assessment-results', observation($this, false))->assertCreated();
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertStatus(422)->assertJsonPath('errors.instrument_id.0', 'The learner has had all 2 attempts. Talk to the moderator about the next step.');
});

it('checks the assessor\'s registration on the day assessed, and their centre', function (): void {
    asTenant($this->tym, function () {
        StaffCredential::query()->where('user_id', $this->naledi->id)->update(['starts_on' => now()->subDay(), 'expires_on' => now()->addYear()]);
        Enrolment::query()->whereKey($this->enrolment)->update(['enrolled_on' => now()->subDays(30)]);
    });
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true, ['assessed_on' => now('Africa/Johannesburg')->subDays(5)->toDateString()]))
        ->assertStatus(422)->assertJsonValidationErrors('assessor');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertCreated();

    // A facilitator is not an assessor; an assessor at another centre can't see this cohort.
    signInAs($this, 'sipho@tym.test');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertForbidden();
    $far = asTenant($this->tym, fn () => Centre::query()->create(['name' => 'Durban Campus', 'city' => 'Durban', 'province' => 'KwaZulu-Natal']));
    makeMember($this->tym, [['assessor', $far->id]], ['email' => 'far@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'far@tym.test');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/results")->assertNotFound();
});

it('needs the checked logbook sign-off before a workplace module is competent, and shows a changed result', function (): void {
    $wm = $this->putJson(TYM_V1."/modules/{$this->wm->id}/instruments", ['instruments' => [
        ['method' => 'logbook', 'title' => 'Workplace logbook', 'criteria' => [['key' => 'hours', 'text' => 'Hours and activities signed off by the mentor']]],
    ]])->assertOk()->json('data.0');
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1.'/assessment-results', ['enrolment_id' => $this->enrolment, 'instrument_id' => $wm['id'], 'outcome' => 'competent', 'assessed_on' => $this->today,
        'criteria' => [['key' => 'hours', 'met' => true]]])->assertStatus(422)->assertJsonPath('errors.outcome.0', 'The workplace logbook for this module has not been signed off by the mentor and checked yet.');

    $id = $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertCreated()->json('data.id');
    asTenant($this->tym, fn () => Result::query()->whereKey($id)->update(['outcome' => 'nyc']));
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/results/{$this->enrolment}")->assertOk()->assertJsonPath('data.history.0.intact', false);
});
