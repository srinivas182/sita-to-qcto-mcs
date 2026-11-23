<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\Notification;
use Modules\Assessment\Models\AssessmentSettings;
use Modules\Assessment\Models\EisaCalendar;
use Modules\Assessment\Notifications\EisaClosing;
use Modules\Files\Models\File;
use Modules\Qualifications\Models\Qualification;

beforeEach(function (): void {
    Notification::fake();
    assessmentSetup($this);
    asTenant($this->tym, fn () => AssessmentSettings::current()->fill(['new_assessor_first' => 0, 'random_pct' => 0])->save());
    $this->putJson(TYM_V1."/modules/{$this->wm->id}/instruments", ['instruments' => [
        ['method' => 'logbook', 'title' => 'Workplace logbook', 'criteria' => [['key' => 'hours', 'text' => 'Hours and activities signed off by the mentor']]],
    ]])->assertOk();
    $this->wmLog = $this->getJson(TYM_V1."/modules/{$this->wm->id}/instruments")->json('data.instruments.0');
    allCompetent($this);
    signInOwner($this, $this->tym, 'office2@tym.test');
    $this->postJson(TYM_V1."/enrolments/{$this->enrolment}/statement-of-results", ['confirm' => true])->assertCreated();
    [$this->soon, $this->later, $this->closed] = asPlatform(fn () => [
        EisaCalendar::query()->create(['qualification_id' => $this->q->id, 'quality_partner' => 'CETA', 'eisa_on' => now()->addDays(40)->toDateString(), 'closes_on' => now()->addDays(10)->toDateString(), 'source' => 'CETA EISA plan 2027']),
        EisaCalendar::query()->create(['qualification_id' => $this->q->id, 'quality_partner' => 'CETA', 'eisa_on' => now()->addDays(120)->toDateString(), 'closes_on' => now()->addDays(80)->toDateString(), 'source' => 'CETA EISA plan 2027']),
        EisaCalendar::query()->create(['qualification_id' => $this->q->id, 'quality_partner' => 'CETA', 'eisa_on' => now()->addDays(5)->toDateString(), 'closes_on' => now()->subDay()->toDateString(), 'source' => 'CETA EISA plan 2027']),
    ]);
});

function flcFile(object $test): string
{
    return asTenant($test->tym, fn () => File::query()->create(['owner_id' => $test->tymOwner->id ?? $test->naledi->id, 'purpose' => 'flc_evidence', 'original_name' => 'flc.pdf',
        'mime' => 'application/pdf', 'size' => 10, 'store_driver' => 'local', 'path' => 'x/flc.pdf', 'status' => 'ready', 'sha256' => str_repeat('b', 64)])->id);
}

it('registers a ready learner before the closing date, with the centre, FLC evidence and the learner\'s declaration', function (): void {
    $eisa = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/eisa")->assertOk()->json('data');
    expect($eisa['next']['key'])->toBe('c:'.$this->soon->id)->and($eisa['days_to_close'])->toBe(10)
        ->and($eisa['learners'][0]['missing'])->toBe(['Choose the EISA date and assessment centre.']);

    $put = fn (array $b) => $this->putJson(TYM_V1."/enrolments/{$this->enrolment}/eisa-registration", $b);
    $put(['date' => 'c:'.$this->closed->id])->assertStatus(422)->assertJsonPath('errors.date.0', fn (string $m) => str_contains($m, 'closed (no late registrations)'));
    $put(['date' => 'c:'.$this->soon->id, 'flc_code' => '02', 'flc_number' => 'RPL2026'])->assertStatus(422)->assertJsonValidationErrors('flc_number');
    $put(['date' => 'c:'.$this->soon->id, 'flc_code' => '06'])->assertStatus(422)->assertJsonValidationErrors('flc_code'); // NQF 4
    $reg = $put(['date' => 'c:'.$this->soon->id, 'assessment_centre' => 'ac0099/22-0001', 'assessment_centre_name' => 'Soweto Trade Test Centre', 'flc_code' => '01', 'flc_number' => 'FLC-2026-1234'])
        ->assertOk()->assertJsonPath('data.assessment_centre', 'AC0099/22-0001')->json('data');
    $this->postJson(TYM_V1."/eisa-registrations/{$reg['id']}/register")->assertStatus(422)->assertJsonPath('errors.registration', ['Upload the FLC evidence.', 'The learner has not signed the EISA declaration.']);
    $put(['date' => 'c:'.$this->soon->id, 'assessment_centre' => 'AC0099/22-0001', 'assessment_centre_name' => 'Soweto Trade Test Centre', 'flc_code' => '01', 'flc_number' => 'FLC-2026-1234', 'flc_file_id' => flcFile($this)])->assertOk();

    signInAs($this, 'ayanda@learner.test');
    $mine = $this->getJson(TYM_V1.'/me/eisa')->assertOk()->json();
    expect($mine['declaration'])->toContain('I will write the EISA');
    $this->postJson(TYM_V1."/me/eisa/{$reg['id']}/declare", ['confirm' => true, 'name' => 'Someone Else'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson(TYM_V1."/me/eisa/{$reg['id']}/declare", ['confirm' => true, 'name' => strtoupper($this->ayanda->fullName())])->assertOk();

    signInOwner($this, $this->tym, 'office3@tym.test');
    $this->postJson(TYM_V1."/eisa-registrations/{$reg['id']}/register")->assertOk()->assertJsonPath('data.status', 'registered');
    $put(['date' => 'c:'.$this->later->id])->assertStatus(422); // registered: withdraw first

    // The QCTO file now carries the centre and FLC, and passes the readiness rules.
    $row = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment")->json('data.rows.0');
    expect($row['columns'])->toMatchArray(['AK' => '01', 'AM' => 'AC0099/22-0001', 'AN' => '4', 'AO' => '01', 'AP' => 'FLC-2026-1234'])
        ->and(collect($row['errors'])->pluck('code')->intersect(['Q-AC', 'Q-FLC', 'Q-SOR-EISA'])->all())->toBe([]);

    // The pack: ID copy (missing here), SoR, declaration, QCTO proof, README.
    $res = $this->get(TYM_V1."/eisa-registrations/{$reg['id']}/pack")->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'z').'.zip';
    file_put_contents($path, $res->getContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    expect($names)->toBe(['2-statement-of-results.pdf', '3-eisa-declaration.pdf', '4-qcto-submission.pdf', 'README.txt'])
        ->and($zip->getFromName('README.txt'))->toContain('ID copy: MISSING')->toContain('(NOT SENT YET)');
    $this->get(TYM_V1."/cohorts/{$this->cohort->id}/eisa-packs")->assertOk();
});

it('lets the provider add or correct a date with its source, and warns before registration closes', function (): void {
    $this->postJson(TYM_V1.'/eisa-dates', ['qualification_id' => $this->q->id, 'replaces_id' => $this->soon->id])->assertStatus(422)->assertJsonValidationErrors('source');
    $this->postJson(TYM_V1.'/eisa-dates', ['qualification_id' => $this->q->id, 'replaces_id' => $this->soon->id, 'source' => 'CETA email of 3 March: this date moved'])->assertCreated();
    $this->postJson(TYM_V1.'/eisa-dates', ['qualification_id' => $this->q->id, 'eisa_on' => now()->addDays(60)->toDateString(), 'closes_on' => now()->addDays(20)->toDateString(),
        'source' => 'CETA email of 3 March'])->assertCreated();
    $dates = $this->getJson(TYM_V1.'/eisa-dates?qualification_id='.$this->q->id)->assertOk()->json('data');
    expect(array_column($dates, 'own'))->toBe([false, true, false]) // the closed date, ours, the later one; the replaced one is gone
        ->and($this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/eisa")->json('data.days_to_close'))->toBe(20);

    $this->artisan('eisa:closing')->expectsOutput('1 EISA reminders sent')->assertSuccessful();
    $this->artisan('eisa:closing')->expectsOutput('0 EISA reminders sent')->assertSuccessful();
    Notification::assertSentOnDemand(EisaClosing::class);

    // Staff without cohorts.manage and other learners can't register or declare.
    signInAs($this, 'naledi@tym.test');
    $this->putJson(TYM_V1."/enrolments/{$this->enrolment}/eisa-registration", ['date' => 'c:'.$this->later->id])->assertForbidden();
});

it('keeps the platform calendar to platform staff', function (): void {
    $this->postJson(TYM_V1.'/platform/eisa-calendar', ['qualification_id' => $this->q->id, 'eisa_on' => now()->addDays(200)->toDateString(),
        'closes_on' => now()->addDays(150)->toDateString(), 'source' => 'x'])->assertForbidden();
    expect(asPlatform(fn () => Qualification::query()->whereKey($this->q->id)->value('nqf_level')))->toBeLessThan(5);
});
