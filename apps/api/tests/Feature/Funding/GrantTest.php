<?php

declare(strict_types=1);

require_once __DIR__.'/../Assessment/helpers.php';

use Illuminate\Support\Facades\Notification;
use Modules\Assessment\Models\AssessmentSettings;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\RegisterSignature;
use Modules\Enrolment\Models\QctoOutcome;
use Modules\Enrolment\Models\QctoSubmission;
use Modules\Files\Models\File;

beforeEach(function (): void {
    Notification::fake();
    assessmentSetup($this);
    asTenant($this->tym, fn () => AssessmentSettings::current()->fill(['new_assessor_first' => 0, 'random_pct' => 0])->save());
    $this->putJson(TYM_V1."/modules/{$this->wm->id}/instruments", ['instruments' => [
        ['method' => 'logbook', 'title' => 'Workplace logbook', 'criteria' => [['key' => 'hours', 'text' => 'Hours and activities signed off by the mentor']]],
    ]])->assertOk();
    $this->wmLog = $this->getJson(TYM_V1."/modules/{$this->wm->id}/instruments")->json('data.instruments.0');
    $this->funder = $this->postJson(TYM_V1.'/funders', ['name' => 'CETA', 'kind' => 'seta', 'seta_code' => 'CETA', 'template' => 'ceta'])->assertCreated()->json('data');
});

function agreement(object $test, array $over = []): array
{
    return array_merge([
        'funder_id' => $test->funder['id'], 'reference' => 'CETA/DG/2027/014', 'learners' => 20, 'amount_per_learner' => 45500, 'funding_kind' => 'full',
        'starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'enrolment_ids' => [$test->enrolment],
        'tranches' => [
            ['share_pct' => 40, 'milestones' => [['key' => 'enrolled'], ['key' => 'qcto_submitted'], ['key' => 'registers', 'months' => 2]]],
            ['share_pct' => 60, 'milestones' => [['key' => 'logbook'], ['key' => 'sor']], 'evidence' => [['title' => 'Certificates received from the QCTO']]],
        ],
    ], $over);
}

function grantFile(object $test): string
{
    return asTenant($test->tym, fn () => File::query()->create(['owner_id' => $test->naledi->id, 'purpose' => 'grant_document', 'original_name' => 'certs.pdf',
        'mime' => 'application/pdf', 'size' => 10, 'store_driver' => 'local', 'path' => 'x/certs.pdf', 'status' => 'ready', 'sha256' => str_repeat('c', 64)])->id);
}

it('checks an agreement before saving it', function (): void {
    $post = fn (array $over) => $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/grant-agreements", agreement($this, $over));
    $post(['tranches' => [['share_pct' => 70, 'milestones' => [['key' => 'sor']]], ['share_pct' => 40, 'milestones' => [['key' => 'eisa']]]]])
        ->assertStatus(422)->assertJsonPath('errors.tranches.0', 'The tranches add up to 110%; they can’t be more than 100%.');
    $post(['tranches' => [['share_pct' => 50, 'milestones' => []]]])->assertStatus(422)->assertJsonValidationErrors('tranches.0.milestones');
    $post(['learners' => 0])->assertStatus(422)->assertJsonValidationErrors('learners');
    $post(['enrolment_ids' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ']])->assertStatus(422)->assertJsonPath('errors.enrolment_ids.0', 'Every learner must be enrolled in this cohort.');
    $post(['tranches' => [['share_pct' => 50, 'milestones' => [['key' => 'bribe']]]]])->assertStatus(422)->assertJsonValidationErrors('tranches.0.milestones.0.key');
    $post([])->assertCreated()->assertJsonPath('data.total_cents', 91000000);
    $post([])->assertStatus(422)->assertJsonValidationErrors('reference');

    // A facilitator can't see funding.
    signInAs($this, 'naledi@tym.test');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/grant-agreements")->assertForbidden();
});

it('works out each tranche from the learner\'s records and names what is missing', function (): void {
    $a = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/grant-agreements", agreement($this))->assertCreated()->json('data');
    $show = fn () => $this->getJson(TYM_V1."/grant-agreements/{$a['id']}")->assertOk()->json('data');

    $p = $show();
    expect($p['learners'][0]['tranches'][$p['tranches'][0]['id']]['blockers'])->toBe(['Not on a QCTO enrolment submission yet.', '0 of 2 monthly registers signed.'])
        ->and($p['tranches'][0]['per_learner_cents'])->toBe(1820000)
        ->and($p['tranches'][1]['learners_met'])->toBe(0);

    // A QCTO submission and two signed registers meet tranche 1; an open QCTO rejection undoes it.
    $sub = asTenant($this->tym, function () {
        $s = QctoSubmission::query()->create(['cohort_id' => $this->cohort->id, 'stage' => 'enrolment', 'learner_ids' => [$this->ayanda->id], 'rows' => 1, 'sent_on' => now()->toDateString()]);
        foreach ([2, 1] as $back) {
            $r = AttendanceRegister::query()->create(['cohort_id' => $this->cohort->id, 'month' => now()->subMonths($back)->startOfMonth(), 'grid' => ['sessions' => [], 'learners' => []], 'grid_sha256' => str_repeat('d', 64)]);
            $r->forceFill(['status' => 'submitted'])->save();
            RegisterSignature::query()->create(['register_id' => $r->id, 'user_id' => $this->ayanda->id, 'role' => 'learner'])->forceFill(['status' => 'signed', 'signed_at' => now()])->save();
        }

        return $s;
    });
    $p = $show();
    $t1 = $p['tranches'][0]['id'];
    expect($p['learners'][0]['tranches'][$t1])->toBe(['met' => true, 'blockers' => []])
        ->and($p['tranches'][0]['learners_met'])->toBe(1)->and($p['tranches'][0]['claimable_cents'])->toBe(1820000)->and($p['tranches'][0]['ready'])->toBeTrue();
    asTenant($this->tym, fn () => QctoOutcome::query()->create(['submission_id' => $sub->id, 'user_id' => $this->ayanda->id, 'status' => 'rejected', 'reason' => 'ID number not found']));
    expect($show()['learners'][0]['tranches'][$t1]['blockers'])->toBe(['Rejected by the QCTO: ID number not found']);

    // Tranche 2 waits for the SoR and for evidence only the provider holds.
    allCompetent($this);
    signInOwner($this, $this->tym, 'office2@tym.test');
    $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results", ['confirm' => true])->assertCreated();
    $p = $show();
    $t2 = $p['tranches'][1];
    expect($t2['learners_met'])->toBe(1)->and($t2['evidence_missing'])->toBe(1)->and($t2['ready'])->toBeFalse();
    $this->postJson(TYM_V1."/grant-tranches/{$t2['id']}/evidence", ['index' => 0, 'file_id' => grantFile($this)])->assertOk();
    expect($show()['tranches'][1]['ready'])->toBeTrue();

    // Taking the learner off keeps the row and stops counting them.
    $this->putJson(TYM_V1."/grant-agreements/{$a['id']}", agreement($this, ['enrolment_ids' => []]))->assertOk();
    $p = $show();
    expect($p['learners'][0]['status'])->toBe('removed')->and($p['tranches'][1]['learners_met'])->toBe(0)->and($p['agreement']['active_learners'])->toBe(0);
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/grant-agreements")->assertOk()->assertJsonCount(1, 'data');
});
