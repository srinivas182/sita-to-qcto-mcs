<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Modules\Assessment\Models\AssessmentSettings;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Logbook\Models\LogbookSignoff;

beforeEach(function (): void {
    assessmentSetup($this);
    asTenant($this->tym, fn () => AssessmentSettings::current()->fill(['new_assessor_first' => 0, 'random_pct' => 0])->save()); // nothing sampled except Not yet competent
    $this->wmLog = $this->putJson(TYM_V1."/modules/{$this->wm->id}/instruments", ['instruments' => [
        ['method' => 'logbook', 'title' => 'Workplace logbook', 'criteria' => [['key' => 'hours', 'text' => 'Hours and activities signed off by the mentor']]],
    ]])->assertOk()->json('data.0');
});

/** Every module competent and final; the work experience logbook checked. */
function allCompetent(object $test): void
{
    asTenant($test->tym, fn () => LogbookSignoff::query()->create(['enrolment_id' => $test->enrolment, 'user_id' => $test->ayanda->id, 'module_id' => $test->wm->id,
        'mentor_id' => $test->mentor->id, 'signed_at' => now(), 'minutes' => 24000, 'signature' => str_repeat('a', 64), 'statement_version' => 'v1', 'status' => 'checked']));
    signInAs($test, 'naledi@tym.test');
    $test->postJson(TYM_V1.'/assessment-results', ['enrolment_id' => $test->enrolment, 'instrument_id' => $test->kmTest['id'], 'assessed_on' => $test->today,
        'criteria' => [['key' => 'k1', 'met' => true]], 'outcome' => 'competent', 'score' => 70])->assertCreated();
    $test->postJson(TYM_V1.'/assessment-results', observation($test, true))->assertCreated();
    $test->postJson(TYM_V1.'/assessment-results', ['enrolment_id' => $test->enrolment, 'instrument_id' => $test->wmLog['id'], 'assessed_on' => $test->today,
        'criteria' => [['key' => 'hours', 'met' => true]], 'outcome' => 'competent'])->assertCreated();
}

it('lists what stands between each learner and the Statement of Results', function (): void {
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1.'/assessment-results', observation($this, false))->assertCreated(); // sampled, waiting
    signInOwner($this, $this->tym, 'office2@tym.test');
    $r = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/statement-readiness")->assertOk()->json('data.0');
    expect($r['ready'])->toBeFalse()->and($r['blockers'])->toBe([
        'KM-01: not yet competent in every assessment.', 'PM-01: not yet competent in every assessment.',
        'WM-01: not yet competent in every assessment.', 'WM-01: the workplace logbook is not signed off and checked.',
    ]);
});

it('issues a numbered, fingerprinted statement that can be verified publicly and fills the QCTO file', function (): void {
    allCompetent($this);
    signInOwner($this, $this->tym, 'office2@tym.test');
    expect($this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/statement-readiness")->json('data.0'))->toMatchArray(['ready' => true, 'blockers' => []]);
    $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results")->assertStatus(422)->assertJsonValidationErrors('confirm');
    $sor = $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results", ['confirm' => true])->assertCreated()->json('data');
    expect($sor['number'])->toBe('TYM-SOR-'.now()->year.'-00001');
    $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results", ['confirm' => true])->assertStatus(422);
    $pdf = $this->get(TYM_V1."/statements-of-results/{$sor['id']}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');

    $row = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment")->assertOk()->json('data.rows.0.columns');
    expect($row)->toMatchArray(['AK' => '01', 'AL' => now('Africa/Johannesburg')->format('Ymd'), 'AN' => '4']);

    // Public check, no sign-in.
    $this->app['auth']->forgetGuards();
    $v = $this->withHeaders(['Authorization' => ''])->getJson(TYM_V1."/public/sor/{$sor['verify_code']}")->assertOk()->json('data');
    expect($v)->toMatchArray(['number' => $sor['number'], 'status' => 'issued', 'learner' => $this->ayanda->fullName(), 'intact' => true, 'modules' => 3]);

    // The learner sees and downloads it.
    signInAs($this, 'ayanda@learner.test');
    $this->getJson(TYM_V1.'/me/statements-of-results')->assertOk()->assertJsonPath('data.0.number', $sor['number']);
    $this->get(TYM_V1."/statements-of-results/{$sor['id']}/pdf")->assertOk();
});

it('withdraws a statement with a reason so a corrected one can be issued; a changed one shows', function (): void {
    allCompetent($this);
    signInOwner($this, $this->tym, 'office2@tym.test');
    $first = $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results", ['confirm' => true])->assertCreated()->json('data');
    $this->postJson(TYM_V1."/statements-of-results/{$first['id']}/withdraw", ['reason' => 'short'])->assertStatus(422);
    $this->postJson(TYM_V1."/statements-of-results/{$first['id']}/withdraw", ['reason' => 'Wrong module title on the curriculum; corrected and re-issued.'])->assertOk()->assertJsonPath('data.status', 'withdrawn');
    $second = $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results", ['confirm' => true])->assertCreated()->json('data');
    expect($second['number'])->toEndWith('-00002');
    $this->getJson(TYM_V1."/public/sor/{$first['verify_code']}")->assertOk()->assertJsonPath('data.status', 'withdrawn');

    asTenant($this->tym, fn () => StatementOfResults::query()->whereKey($second['id'])->update(['snapshot' => ['learner' => ['name' => 'Someone else']]]));
    $this->getJson(TYM_V1."/public/sor/{$second['verify_code']}")->assertOk()->assertJsonPath('data.intact', false);

    // Assessors and learners can't issue.
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results", ['confirm' => true])->assertForbidden();
});
