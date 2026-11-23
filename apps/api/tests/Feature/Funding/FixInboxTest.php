<?php

declare(strict_types=1);

require_once __DIR__.'/../Assessment/helpers.php';

use Illuminate\Support\Facades\Notification;
use Modules\Enrolment\Models\QctoOutcome;
use Modules\Enrolment\Models\QctoSubmission;

beforeEach(function (): void {
    Notification::fake();
    assessmentSetup($this);
    $funder = $this->postJson(TYM_V1.'/funders', ['name' => 'CETA', 'kind' => 'seta'])->assertCreated()->json('data');
    $this->agreement = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/grant-agreements", [
        'funder_id' => $funder['id'], 'reference' => 'CETA/DG/9', 'learners' => 5, 'amount_per_learner' => 10000, 'funding_kind' => 'full',
        'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'enrolment_ids' => [$this->enrolment],
        'tranches' => [['share_pct' => 50, 'milestones' => [['key' => 'enrolled']]], ['share_pct' => 50, 'milestones' => [['key' => 'enrolled']], 'evidence' => [['title' => 'Letter']]]],
    ])->assertCreated()->json('data');
});

it('lists what is stuck, most urgent first, each with where to fix it', function (): void {
    $inbox = $this->getJson(TYM_V1.'/fix-inbox')->assertOk()->json('data');
    expect(collect($inbox['items'])->pluck('kind')->all())->toBe(['evidence', 'claim'])
        ->and($inbox['items'][1]['link'])->toBe(['to' => '/grant-agreements/$id', 'params' => ['id' => $this->agreement['id']]])
        ->and($inbox['funding']['claimable_cents'])->toBe(500000);

    $tranche = $this->getJson(TYM_V1."/grant-agreements/{$this->agreement['id']}")->json('data.tranches.0.id');
    $claim = $this->postJson(TYM_V1."/grant-tranches/{$tranche}/claims")->assertCreated()->json('data');
    $this->postJson(TYM_V1."/grant-claims/{$claim['id']}/sent", ['sent_on' => now()->toDateString()])->assertOk();
    $this->postJson(TYM_V1."/grant-claims/{$claim['id']}/queries", ['problem' => 'ID copy not legible', 'enrolment_id' => $this->enrolment])->assertCreated();
    asTenant($this->tym, function () {
        $s = QctoSubmission::query()->create(['cohort_id' => $this->cohort->id, 'stage' => 'enrolment', 'learner_ids' => [$this->ayanda->id], 'rows' => 1, 'sent_on' => now()->toDateString()]);
        QctoOutcome::query()->create(['submission_id' => $s->id, 'user_id' => $this->ayanda->id, 'status' => 'rejected', 'reason' => 'ID not found']);
    });

    $inbox = $this->getJson(TYM_V1.'/fix-inbox')->assertOk()->json('data');
    expect(collect($inbox['items'])->pluck('kind')->all())->toBe(['qcto', 'claim_query', 'evidence'])
        ->and($inbox['items'][1]['title'])->toBe("1 open query(ies) on claim {$claim['number']}")
        ->and($inbox['funding'])->toMatchArray(['claimable_cents' => 0, 'claimed_open_cents' => 500000]);

    // An assessor sees no funding and nothing for cohorts they can't see.
    signInAs($this, 'naledi@tym.test');
    expect($this->getJson(TYM_V1.'/fix-inbox')->assertOk()->json('data.funding'))->toBeNull();
});

it('tells a learner what they can fix themselves', function (): void {
    signInAs($this, 'ayanda@learner.test');
    expect($this->getJson(TYM_V1.'/me/todo')->assertOk()->json('data'))->toMatchArray(['bank_missing' => false, 'eisa_to_sign' => 0]);

    signInOwner($this, $this->tym, 'office2@tym.test');
    $this->putJson(TYM_V1."/grant-agreements/{$this->agreement['id']}/stipend-rule", ['stipend' => 1500, 'full_pct' => 80, 'hold_pct' => 50, 'prorata' => true])->assertOk();
    signInAs($this, 'ayanda@learner.test');
    $this->getJson(TYM_V1.'/me/todo')->assertOk()->assertJsonPath('data.bank_missing', true);
    $this->putJson(TYM_V1.'/me/bank-account', ['holder_name' => 'A Zulu', 'bank' => 'fnb', 'account_number' => '62000000001', 'account_type' => 'cheque'])->assertOk();
    $this->getJson(TYM_V1.'/me/todo')->assertOk()->assertJsonPath('data.bank_missing', false);
});
