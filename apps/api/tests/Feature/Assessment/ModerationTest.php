<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Modules\Assessment\Models\AssessmentSettings;
use Modules\Assessment\Models\Result;
use Modules\Assessment\Models\Sample;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Enrolment\Models\Enrolment;

beforeEach(fn () => assessmentSetup($this));

function assessmentPolicy(object $test, array $values): void
{
    asTenant($test->tym, fn () => AssessmentSettings::current()->fill($values)->save());
}

it('samples every Not yet competent and a new assessor\'s first decisions; the rest are final or sampled by share', function (): void {
    signInAs($this, 'naledi@tym.test');
    $nyc = $this->postJson(TYM_V1.'/assessment-results', observation($this, false))->assertCreated()->json('data');
    $new = $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertCreated()->json('data');
    $reasons = asTenant($this->tym, fn () => Sample::query()->pluck('reason', 'result_id')->all());
    expect($reasons)->toBe([$nyc['id'] => 'nyc', $new['id'] => 'new_assessor'])->and($new['status'])->toBe('decided');

    assessmentPolicy($this, ['new_assessor_first' => 0, 'random_pct' => 0]);
    $test = ['enrolment_id' => $this->enrolment, 'instrument_id' => $this->kmTest['id'], 'assessed_on' => $this->today, 'criteria' => [['key' => 'k1', 'met' => true]], 'outcome' => 'competent', 'score' => 80];
    $this->postJson(TYM_V1.'/assessment-results', $test)->assertCreated()->assertJsonPath('data.status', 'final');

    // A decision by the class's own facilitator is always moderated.
    asTenant($this->tym, fn () => CohortStaff::query()->create(['cohort_id' => $this->cohort->id, 'user_id' => $this->naledi->id, 'role' => 'facilitator']));
    $lerato = enrolLearner($this, '082 222 3333', '9001014800089');
    $e2 = asTenant($this->tym, fn () => Enrolment::query()->where('user_id', $lerato->id)->value('id'));
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, true, ['enrolment_id' => $e2]))->assertCreated()->assertJsonPath('data.status', 'decided');
    expect(asTenant($this->tym, fn () => Sample::query()->where('reason', 'facilitator')->count()))->toBe(1);
});

it('lets a registered moderator agree or overturn, never on their own decisions, and signs the report', function (): void {
    signInAs($this, 'naledi@tym.test');
    $nyc = $this->postJson(TYM_V1.'/assessment-results', observation($this, false))->assertCreated()->json('data');
    $ok = $this->postJson(TYM_V1.'/assessment-results', ['enrolment_id' => $this->enrolment, 'instrument_id' => $this->kmTest['id'], 'assessed_on' => $this->today,
        'criteria' => [['key' => 'k1', 'met' => true]], 'outcome' => 'competent', 'score' => 61])->assertCreated()->json('data');
    $this->getJson(TYM_V1.'/me/moderation')->assertForbidden(); // assessors don't moderate

    signInAs($this, 'zanele@tym.test');
    $q = $this->getJson(TYM_V1.'/me/moderation')->assertOk()->json('data.samples');
    expect($q)->toHaveCount(2)->and($q[0])->toMatchArray(['status' => 'open', 'learner' => $this->ayanda->fullName(), 'assessor' => 'Naledi Mthembu']);
    $byResult = collect($q)->keyBy('result_id');
    $this->getJson(TYM_V1.'/moderation-samples/'.$byResult[$nyc['id']]['id'])->assertOk()->assertJsonPath('data.instrument.criteria.1.key', 'bond');
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/moderation-reports", ['module_id' => $this->pm->id, 'findings' => 'Assessor applied the criteria well.', 'confirm' => true])
        ->assertStatus(422)->assertJsonValidationErrors('module_id'); // open sample
    $this->postJson(TYM_V1.'/moderation-samples/'.$byResult[$ok['id']]['id'].'/decide', ['decision' => 'overturn'])->assertStatus(422)->assertJsonValidationErrors('comment');
    $this->postJson(TYM_V1.'/moderation-samples/'.$byResult[$ok['id']]['id'].'/decide', ['decision' => 'overturn', 'comment' => 'Score of 61 is below the corrected mark of 58 after re-marking question 4'])
        ->assertOk()->assertJsonPath('data.decision', 'overturn');
    $this->postJson(TYM_V1.'/moderation-samples/'.$byResult[$nyc['id']]['id'].'/decide', ['decision' => 'agree'])->assertOk();
    $this->postJson(TYM_V1.'/moderation-samples/'.$byResult[$nyc['id']]['id'].'/decide', ['decision' => 'agree'])->assertStatus(422);

    $states = asTenant($this->tym, fn () => Result::query()->orderBy('created_at')->pluck('status', 'id')->all());
    expect($states[$ok['id']])->toBe('overturned')->and($states[$nyc['id']])->toBe('final');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/results/{$this->enrolment}")->assertOk()->assertJsonPath('data.modules.0.state', 'nyc');

    $mod = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/moderation")->assertOk()->json('data');
    expect(collect($mod)->firstWhere('code', 'KM-01'))->toMatchArray(['decisions' => 1, 'sampled' => 1, 'overturned' => 1, 'compliant' => true]);
    $report = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/moderation-reports", ['module_id' => $this->km->id, 'findings' => 'One test re-marked; the assessor to re-check totals.', 'confirm' => true])
        ->assertCreated()->json('data');
    $pdf = $this->get(TYM_V1."/moderation-reports/{$report['id']}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');

    // Zanele can't moderate a decision she made herself.
    $own = $this->postJson(TYM_V1.'/assessment-results', observation($this, true))->assertCreated()->json('data');
    $sid = asTenant($this->tym, fn () => Sample::query()->where('result_id', $own['id'])->value('id'));
    $this->postJson(TYM_V1."/moderation-samples/{$sid}/decide", ['decision' => 'agree'])->assertStatus(422)->assertJsonValidationErrors('moderator');
});

it('takes a learner\'s appeal within the window, decided by a moderator who is not the assessor', function (): void {
    signInAs($this, 'naledi@tym.test');
    $nyc = $this->postJson(TYM_V1.'/assessment-results', observation($this, false))->assertCreated()->json('data');

    signInAs($this, 'ayanda@learner.test');
    $latest = $this->getJson(TYM_V1.'/me/results')->json('data.0.modules.1.instruments.0.latest');
    expect($latest)->toMatchArray(['can_appeal' => true, 'appeal' => null]);
    $this->postJson(TYM_V1."/assessment-results/{$nyc['id']}/appeal", ['reason' => 'short'])->assertStatus(422);
    $appeal = $this->postJson(TYM_V1."/assessment-results/{$nyc['id']}/appeal", ['reason' => 'The bond was checked by the facilitator during the build and was fine.'])->assertCreated()->json('data');
    $this->postJson(TYM_V1."/assessment-results/{$nyc['id']}/appeal", ['reason' => 'Again, the bond was fine on every course.'])->assertStatus(422);

    signInAs($this, 'zanele@tym.test');
    $this->getJson(TYM_V1.'/me/moderation')->assertOk()->assertJsonPath('data.appeals.0.status', 'open')->assertJsonPath('data.appeals.0.overdue', false);
    $this->postJson(TYM_V1."/appeals/{$appeal['id']}/decide", ['outcome' => 'overturned', 'note' => 'Photos show the bond kept; course 4 was a closer, not a break.'])
        ->assertOk()->assertJsonPath('data.status', 'overturned');

    signInAs($this, 'ayanda@learner.test');
    $m = $this->getJson(TYM_V1.'/me/results')->json('data.0.modules.1');
    expect($m['state'])->toBe('competent')->and($m['instruments'][0]['latest']['feedback'])->toContain('closer');

    // Too late to appeal.
    signInAs($this, 'naledi@tym.test');
    $test = $this->postJson(TYM_V1.'/assessment-results', ['enrolment_id' => $this->enrolment, 'instrument_id' => $this->kmTest['id'], 'assessed_on' => $this->today,
        'criteria' => [['key' => 'k1', 'met' => false]], 'outcome' => 'nyc', 'score' => 40, 'feedback' => 'Revise mortar types'])->assertCreated()->json('data');
    $this->travel(15)->days();
    signInAs($this, 'ayanda@learner.test');
    $this->postJson(TYM_V1."/assessment-results/{$test['id']}/appeal", ['reason' => 'I think the paper was marked wrongly on question two.'])->assertStatus(422)
        ->assertJsonPath('errors.reason.0', 'Appeals must be made within 14 days of the result.');
});
