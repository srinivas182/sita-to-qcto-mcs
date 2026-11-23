<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Modules\Identity\Models\User;
use Modules\People\Services\ProfileService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function (): void {
    Notification::fake();
    enrolmentSetup($this);
    asTenant($this->tym, fn () => $this->centre->forceFill(['accreditation_number' => 'SDP0712/24-0001'])->save());
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $this->ayanda = enrolLearner($this, '082 123 4567', '9202204720083');
    $this->kagiso = enrolLearner($this, '082 222 3333', '9001014800089', 'Kagiso');
    foreach ([$this->ayanda, $this->kagiso] as $l) {
        fullProfile($this, $l);
    }
    signInOwner($this, $this->tym, 'qcto@tym.test');
    $this->get(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/workbook?acknowledge=1")->assertOk();
    $this->sub = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/sent", ['sent_on' => now()->toDateString()])->assertCreated()->json('data.sent.0.id');
});

function fullProfile(object $test, User $learner): void
{
    asTenant($test->tym, fn () => app(ProfileService::class)->update($learner, [
        'title' => 'Ms', 'home_language' => 'isiZulu', 'address_line1' => '12 Vilakazi Street', 'address_line2' => 'Orlando West', 'city' => 'Soweto',
        'province' => 'Gauteng', 'postal_code' => '1804', 'statssa_area_code' => '798', 'socioeconomic_status' => '02',
    ], $learner, false));
}

/** The QCTO's reply: our columns with an error column added. */
function reply(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'reply').'.xlsx';
    $w = new Writer;
    $w->openToFile($path);
    $w->addRow(Row::fromValues(['QCTO learner data validation report']));
    $w->addRow(Row::fromValues(['SDP Code', 'Qualification Id', 'National Id', 'Learner Alternate ID', 'Learner Last Name', 'Error Description']));
    foreach ($rows as $r) {
        $w->addRow(Row::fromValues($r));
    }
    $w->close();

    return new UploadedFile($path, 'LEISA-reply.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('loads the QCTO reply, puts rejected learners back with the reason, and lets them go again once fixed', function (): void {
    $file = reply([
        ['SDP0712/24-0001', '93627', '9202204720083', '', 'Zulu', 'Success'],
        ['SDP0712/24-0001', '93627', '9001014800089', '', 'Zulu', 'Invalid STATSSA area code for the province'],
        ['SDP0712/24-0001', '93627', '8001015009087', '', 'Nobody', 'Learner not found'],
    ]);
    $r = $this->post(TYM_V1."/qcto-submissions/{$this->sub}/response", ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json();
    expect($r['loaded'])->toBe(['accepted' => 1, 'rejected' => 1, 'unmatched' => 1])
        ->and($r['data']['unsent'])->toBe(1)->and($r['data']['sent'][0])->toMatchArray(['accepted' => 1, 'rejected' => 1, 'unmatched' => 1]);
    $kagiso = collect($r['data']['rows'])->firstWhere('user_id', $this->kagiso->id);
    expect(collect($kagiso['errors'])->firstWhere('code', 'Q-QCTO-REJECTED')['problem'])->toContain('Invalid STATSSA area code');
    $this->get(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/workbook?acknowledge=1&only_unsent=1")->assertStatus(422);

    $outcome = collect($r['data']['sent'][0]['learners'])->firstWhere('user_id', $this->kagiso->id)['outcome_id'];
    $after = $this->postJson(TYM_V1."/qcto-outcomes/{$outcome}/resolved")->assertOk()->json('data');
    expect(collect(collect($after['rows'])->firstWhere('user_id', $this->kagiso->id)['errors'])->pluck('code'))->not->toContain('Q-QCTO-REJECTED')
        ->and($after['unsent'])->toBe(1);
    $this->get(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/workbook?acknowledge=1&only_unsent=1")->assertOk();
    $again = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/sent", ['sent_on' => now()->toDateString()])->assertCreated()->json('data');
    expect($again['unsent'])->toBe(0)->and($again['sent'][1]['rows'])->toBe(1);
});

it('marks learners by hand when the QCTO replies by email, and refuses files it cannot read', function (): void {
    $this->postJson(TYM_V1."/qcto-submissions/{$this->sub}/response", ['outcomes' => [['user_id' => $this->ayanda->id, 'status' => 'rejected']]])
        ->assertStatus(422)->assertJsonValidationErrors('outcomes.0.reason');
    $this->postJson(TYM_V1."/qcto-submissions/{$this->sub}/response", ['outcomes' => [['user_id' => $this->ayanda->id, 'status' => 'rejected', 'reason' => 'Home language code missing']]])
        ->assertOk()->assertJsonPath('loaded.rejected', 1);
    $stranger = makeMember($this->tym, ['learner'], ['email' => 'x@y.test']);
    $this->postJson(TYM_V1."/qcto-submissions/{$this->sub}/response", ['outcomes' => [['user_id' => $stranger->id, 'status' => 'accepted']]])->assertStatus(422);
    $bad = UploadedFile::fake()->createWithContent('reply.pdf', 'not a spreadsheet');
    $this->post(TYM_V1."/qcto-submissions/{$this->sub}/response", ['file' => $bad], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
});
