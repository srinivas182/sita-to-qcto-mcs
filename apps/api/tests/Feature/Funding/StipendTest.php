<?php

declare(strict_types=1);

require_once __DIR__.'/../Logbook/helpers.php';
require_once __DIR__.'/../People/helpers.php';

use Illuminate\Support\Facades\Notification;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\RegisterSignature;
use Modules\Files\Models\File;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Services\StipendService;

beforeEach(function (): void {
    Notification::fake();
    logbookSetup($this);
    asPlatform(fn () => $this->ayanda->forceFill(['first_name' => 'Ayanda', 'last_name' => 'Zulu'])->save());
    $funder = $this->postJson(TYM_V1.'/funders', ['name' => 'CETA', 'kind' => 'seta'])->assertCreated()->json('data');
    $this->agreement = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/grant-agreements", [
        'funder_id' => $funder['id'], 'reference' => 'CETA/DG/1', 'learners' => 10, 'amount_per_learner' => 45500, 'funding_kind' => 'full',
        'starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'enrolment_ids' => [$this->enrolment],
        'tranches' => [['share_pct' => 100, 'milestones' => [['key' => 'enrolled']]]],
    ])->assertCreated()->json('data');
    $this->month = now()->subMonth()->format('Y-m');
});

function stipendRegister(object $test, string $signature = 'signed', ?int $pct = 70): void
{
    asTenant($test->tym, function () use ($test, $signature, $pct) {
        $r = AttendanceRegister::query()->create(['cohort_id' => $test->cohort->id, 'month' => $test->month.'-01', 'grid_sha256' => str_repeat('e', 64),
            'grid' => ['sessions' => [], 'learners' => [['user_id' => $test->ayanda->id, 'name' => 'Ayanda Zulu', 'pct' => $pct, 'workplace_days' => 0]]]]);
        $r->forceFill(['status' => 'complete'])->save();
        RegisterSignature::query()->create(['register_id' => $r->id, 'user_id' => $test->ayanda->id, 'role' => 'learner'])->forceFill(['status' => $signature])->save();
    });
}

it('proposes a month from the signed register, pays only learners with bank details, and makes the bank file', function (): void {
    $runs = TYM_V1."/grant-agreements/{$this->agreement['id']}/stipend-runs";
    $this->postJson($runs, ['month' => $this->month])->assertStatus(422)->assertJsonPath('errors.month.0', 'Set the monthly stipend on the agreement first.');
    $this->putJson(TYM_V1."/grant-agreements/{$this->agreement['id']}/stipend-rule", ['stipend' => 1500, 'full_pct' => 50, 'hold_pct' => 80, 'prorata' => true])
        ->assertStatus(422)->assertJsonValidationErrors('hold_pct');
    $this->putJson(TYM_V1."/grant-agreements/{$this->agreement['id']}/stipend-rule", ['stipend' => 1500, 'full_pct' => 80, 'hold_pct' => 50, 'prorata' => true])->assertOk();
    $this->postJson($runs, ['month' => $this->month])->assertStatus(422)->assertJsonPath('errors.month.0', fn (string $m) => str_contains($m, 'signed by the facilitator first'));

    stipendRegister($this);
    $run = $this->postJson($runs, ['month' => $this->month])->assertCreated()->json('data');
    $line = $this->getJson(TYM_V1."/stipend-runs/{$run['id']}")->assertOk()->json('data.lines.0');
    expect($line)->toMatchArray(['attendance_pct' => 70, 'proposed_cents' => 105000, 'status' => 'hold', 'reason' => 'No bank details.', 'bank' => null]);

    // The learner adds their own bank details in the app.
    signInAs($this, 'ayanda@learner.test');
    $this->putJson(TYM_V1.'/me/bank-account', ['holder_name' => 'A Zulu', 'bank' => 'capitec', 'account_number' => '12 34', 'account_type' => 'savings'])
        ->assertStatus(422)->assertJsonPath('errors.account_number.0', 'An account number is 6 to 16 digits, no spaces.');
    $this->putJson(TYM_V1.'/me/bank-account', ['holder_name' => 'A Zulu', 'bank' => 'capitec', 'account_number' => '1234567890', 'account_type' => 'savings'])
        ->assertOk()->assertJsonPath('data.branch_code', '470010')->assertJsonPath('data.account', '•••• 7890')->assertJsonMissingPath('data.account_number');
    $this->getJson(TYM_V1.'/me/stipends')->assertOk()->assertJsonCount(0, 'data'); // drafts aren't shown

    signInOwner($this, $this->tym, 'office2@tym.test');
    $this->postJson($runs, ['month' => $this->month])->assertCreated();
    $line = $this->getJson(TYM_V1."/stipend-runs/{$run['id']}")->json('data.lines.0');
    expect($line)->toMatchArray(['amount_cents' => 105000, 'status' => 'pay', 'reason' => 'Pro-rata: attendance 70%.']);

    $this->patchJson(TYM_V1."/stipend-lines/{$line['id']}", ['amount' => 1500, 'status' => 'pay'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->patchJson(TYM_V1."/stipend-lines/{$line['id']}", ['amount' => 1500, 'status' => 'pay', 'reason' => 'Absences were for a funeral (letter on file)'])->assertOk();
    $this->getJson(TYM_V1."/stipend-runs/{$run['id']}")->assertJsonPath('data.total_cents', 150000);

    $this->get(TYM_V1."/stipend-runs/{$run['id']}/bank-file")->assertStatus(422);
    $this->postJson(TYM_V1."/stipend-runs/{$run['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->patchJson(TYM_V1."/stipend-lines/{$line['id']}", ['amount' => 1, 'status' => 'pay', 'reason' => 'Too late now'])->assertStatus(422);
    $csv = $this->get(TYM_V1."/stipend-runs/{$run['id']}/bank-file")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($csv)->toContain('"Recipient name","Branch code","Account number"')->toContain('"A Zulu",470010,1234567890,2,1500.00,"STIPEND '.strtoupper(now()->subMonth()->format('M Y')).'"');

    $this->postJson(TYM_V1."/stipend-runs/{$run['id']}/paid", ['paid_on' => now()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('proof_file_id');
    $proof = asTenant($this->tym, fn () => File::query()->create(['owner_id' => $this->ayanda->id, 'purpose' => 'grant_document', 'original_name' => 'pop.pdf',
        'mime' => 'application/pdf', 'size' => 10, 'store_driver' => 'local', 'path' => 'x/pop.pdf', 'status' => 'ready', 'sha256' => str_repeat('f', 64)])->id);
    $this->postJson(TYM_V1."/stipend-runs/{$run['id']}/paid", ['paid_on' => now()->toDateString(), 'proof_file_id' => $proof])->assertOk()->assertJsonPath('data.status', 'paid');

    signInAs($this, 'ayanda@learner.test');
    $this->getJson(TYM_V1.'/me/stipends')->assertOk()->assertJsonPath('data.0.status', 'paid')->assertJsonPath('data.0.amount_cents', 150000)
        ->assertJsonPath('bank.account', '•••• 7890');
    $this->getJson(TYM_V1."/stipend-runs/{$run['id']}")->assertForbidden();
});

it('holds a stipend when the register does not back it', function (): void {
    $a = new GrantAgreement(['stipend_cents' => 150000]);
    $row = ['pct' => 90, 'workplace_days' => 0];
    expect(StipendService::decide($a, $row, 'signed', true))->toBe([90, 150000, 'pay', null])
        ->and(StipendService::decide($a, ['pct' => 60, 'workplace_days' => 0], 'signed', true))->toBe([60, 90000, 'pay', 'Pro-rata: attendance 60%.'])
        ->and(StipendService::decide($a, ['pct' => 40, 'workplace_days' => 0], 'signed', true)[2])->toBe('hold')
        ->and(StipendService::decide($a, $row, 'disputed', true)[3])->toBe('The learner disputed the register.')
        ->and(StipendService::decide($a, $row, 'pending', true)[3])->toBe('The learner has not signed the register yet.')
        ->and(StipendService::decide($a, ['pct' => null, 'workplace_days' => 12], 'signed', true))->toBe([100, 150000, 'pay', null])
        ->and(StipendService::decide($a, null, null, true)[3])->toBe('Not on this month’s register.');
    $a->stipend_prorata = false;
    expect(StipendService::decide($a, ['pct' => 60, 'workplace_days' => 0], 'signed', true)[1])->toBe(150000);
});
