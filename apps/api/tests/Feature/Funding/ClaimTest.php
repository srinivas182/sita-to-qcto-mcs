<?php

declare(strict_types=1);

require_once __DIR__.'/../Assessment/helpers.php';

use Illuminate\Support\Facades\Notification;
use Modules\Files\Models\File;

beforeEach(function (): void {
    Notification::fake();
    assessmentSetup($this);
    $funder = $this->postJson(TYM_V1.'/funders', ['name' => 'CETA', 'kind' => 'seta', 'template' => 'ceta'])->assertCreated()->json('data');
    $this->agreement = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/grant-agreements", [
        'funder_id' => $funder['id'], 'reference' => 'CETA/DG/2027/014', 'learners' => 20, 'amount_per_learner' => 45500, 'funding_kind' => 'full',
        'disability_allowance' => 5000, 'starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->addYear()->toDateString(),
        'enrolment_ids' => [$this->enrolment], 'disabled_ids' => [$this->enrolment],
        'tranches' => [
            ['share_pct' => 40, 'milestones' => [['key' => 'enrolled']]],
            ['share_pct' => 60, 'milestones' => [['key' => 'sor']], 'evidence' => [['title' => 'Close-out report']]],
        ],
    ])->assertCreated()->json('data');
    $this->tranches = $this->getJson(TYM_V1."/grant-agreements/{$this->agreement['id']}")->json('data.tranches');
    $this->year = now()->year;
});

it('claims a tranche once per learner, with a gapless number and a complete pack', function (): void {
    [$t1, $t2] = $this->tranches;
    $this->postJson(TYM_V1."/grant-tranches/{$t2['id']}/claims")->assertStatus(422)->assertJsonPath('errors.tranche.0', 'Upload the tranche’s evidence before claiming.');
    $claim = $this->postJson(TYM_V1."/grant-tranches/{$t1['id']}/claims", ['include_disability' => true])->assertCreated()->json('data');
    expect($claim['number'])->toBe("TYM-CLM-{$this->year}-00001")->and($claim['amount_cents'])->toBe(1820000 + 500000)
        ->and($claim['learners'][0]['disability_cents'])->toBe(500000)->and($claim['status'])->toBe('draft');
    $this->postJson(TYM_V1."/grant-tranches/{$t1['id']}/claims")->assertStatus(422)->assertJsonPath('errors.tranche.0', 'No learners have met this tranche since its last claim.');

    $res = $this->get(TYM_V1."/grant-claims/{$claim['id']}/pack")->assertOk()->assertHeader('Content-Type', 'application/zip');
    $path = tempnam(sys_get_temp_dir(), 'z').'.zip';
    file_put_contents($path, $res->getContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    expect($names)->toContain('1-claim-and-invoice.pdf')->toContain('2-learner-schedule.csv')->toContain('README.txt')
        ->and($zip->getFromName('2-learner-schedule.csv'))->toContain('9202204720083')->toContain('18200.00')->toContain('5000.00')
        ->and($zip->getFromName('README.txt'))->toContain('Signed agreement (MoA): MISSING');

    // Sent, queried per learner, fixed, sent again, paid short with a note.
    $sent = fn () => $this->postJson(TYM_V1."/grant-claims/{$claim['id']}/sent", ['sent_on' => now()->toDateString()]);
    $sent()->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson(TYM_V1."/grant-claims/{$claim['id']}/queries", ['problem' => 'Certified ID copy missing', 'enrolment_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])
        ->assertStatus(422)->assertJsonValidationErrors('enrolment_id');
    $q = $this->postJson(TYM_V1."/grant-claims/{$claim['id']}/queries", ['problem' => 'Certified ID copy missing', 'enrolment_id' => $this->enrolment])
        ->assertCreated()->assertJsonPath('data.status', 'queried')->json('data.queries.0');
    $sent()->assertStatus(422)->assertJsonPath('errors.claim.0', 'Fix every query before sending the claim again.');
    $this->postJson(TYM_V1."/claim-queries/{$q['id']}/resolved", [])->assertStatus(422);
    $this->postJson(TYM_V1."/claim-queries/{$q['id']}/resolved", ['resolution' => 'Certified copy uploaded to the SETA portal'])->assertOk();
    $sent()->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson(TYM_V1."/grant-claims/{$claim['id']}/paid", ['paid_on' => now()->toDateString(), 'amount' => 18200])
        ->assertStatus(422)->assertJsonValidationErrors('note');
    $this->postJson(TYM_V1."/grant-claims/{$claim['id']}/paid", ['paid_on' => now()->toDateString(), 'amount' => 18200, 'note' => 'Disability allowance paid with tranche 2'])
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_cents', 1820000);
    $this->getJson(TYM_V1."/grant-agreements/{$this->agreement['id']}/claims")->assertOk()->assertJsonCount(1, 'data');
});

it('frees the learners when a claim is rejected or a draft is cancelled, and never reuses a number', function (): void {
    $t1 = $this->tranches[0];
    $c1 = $this->postJson(TYM_V1."/grant-tranches/{$t1['id']}/claims")->assertCreated()->json('data');
    $this->postJson(TYM_V1."/grant-claims/{$c1['id']}/rejected", ['reason' => 'x'])->assertStatus(422); // a draft can't be rejected
    $this->postJson(TYM_V1."/grant-claims/{$c1['id']}/void", ['reason' => 'Wrong tranche chosen'])->assertOk()->assertJsonPath('data.status', 'void');
    $c2 = $this->postJson(TYM_V1."/grant-tranches/{$t1['id']}/claims")->assertCreated()->json('data');
    expect($c2['number'])->toBe("TYM-CLM-{$this->year}-00002");
    $this->postJson(TYM_V1."/grant-claims/{$c2['id']}/sent", ['sent_on' => now()->toDateString()])->assertOk();
    $this->postJson(TYM_V1."/grant-claims/{$c2['id']}/rejected", ['reason' => 'Agreement not countersigned'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson(TYM_V1."/grant-tranches/{$t1['id']}/claims")->assertCreated()->assertJsonPath('data.number', "TYM-CLM-{$this->year}-00003");

    // Evidence attached later makes tranche 2 claimable once the SoR is issued.
    $file = asTenant($this->tym, fn () => File::query()->create(['owner_id' => $this->naledi->id, 'purpose' => 'grant_document', 'original_name' => 'closeout.pdf',
        'mime' => 'application/pdf', 'size' => 10, 'store_driver' => 'local', 'path' => 'x/closeout.pdf', 'status' => 'ready', 'sha256' => str_repeat('a', 64)])->id);
    $this->postJson(TYM_V1."/grant-tranches/{$this->tranches[1]['id']}/evidence", ['index' => 0, 'file_id' => $file])->assertOk();
    $this->postJson(TYM_V1."/grant-tranches/{$this->tranches[1]['id']}/claims")->assertStatus(422)
        ->assertJsonPath('errors.tranche.0', 'No learners have met this tranche since its last claim.');
});
