<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\IdentityCheck;
use Modules\Enrolment\Models\LearnerDocument;
use Modules\Enrolment\Notifications\QctoEnrolmentDue;
use Modules\Enrolment\Support\WorkingDays;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\People\Services\ProfileService;
use Modules\Settings\Models\Integration;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    Notification::fake();
    enrolmentSetup($this);
    asTenant($this->tym, fn () => $this->centre->forceFill(['accreditation_number' => 'SDP0712/24-0001'])->save());
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
});

function completeProfile(object $test, User $learner): void
{
    asTenant($test->tym, fn () => app(ProfileService::class)->update($learner, [
        'title' => 'Ms', 'home_language' => 'isiZulu', 'address_line1' => '12 Vilakazi Street', 'address_line2' => 'Orlando West', 'city' => 'Soweto',
        'province' => 'Gauteng', 'postal_code' => '1804', 'statssa_area_code' => '798', 'socioeconomic_status' => '02',
    ], $learner, false));
}

it('lists what each learner still needs for the QCTO file, and counts down to the due date', function (): void {
    $learner = enrolLearner($this, '082 123 4567', '9202204720083');
    signInOwner($this, $this->tym, 'qcto@tym.test');
    $list = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment")->assertOk()->json('data');
    expect($list['total'])->toBe(1)->and($list['ready'])->toBe(0)->and($list['unsent'])->toBe(1)
        ->and($list['due']['due_on'])->toBe($this->cohort->intake_on->copy()->addDays(21)->toDateString());
    $row = $list['rows'][0];
    expect(collect($row['errors'])->pluck('column')->all())->toBe(['H', 'R', 'T', 'U', 'Z', 'AF', 'AG'])
        ->and($row['columns'])->toMatchArray(['A' => 'SDP0712/24-0001', 'C' => '9202204720083', 'E' => '533', 'F' => 'U', 'G' => 'SA', 'I' => 'F', 'J' => 'SA',
            'N' => '03', 'O' => 'Zulu', 'P' => 'Ayanda', 'S' => '19920220', 'AC' => '0821234567', 'AH' => 'Yes', 'AN' => '1'])
        ->and(collect($row['warnings'])->pluck('column')->all())->toBe(['F', 'K', 'L', 'B']); // B: the centre's accreditation scope isn't recorded in this fixture

    completeProfile($this, $learner);
    $row = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment")->json('data.rows.0');
    expect($row['errors'])->toBe([])->and($row['columns'])->toMatchArray(['H' => 'Zul', 'R' => 'Ms', 'T' => '12 Vilakazi Street', 'U' => 'Orlando West', 'V' => 'Soweto',
        'W' => '12 Vilakazi Street', 'Z' => '1804', 'AA' => '1804', 'AF' => '7', 'AG' => '798', 'K' => '02']);

    // Working days instead, where the provider chooses.
    $this->patchJson(TYM_V1.'/enrolment-settings', ['qcto_working_days' => true])->assertOk()->assertJsonPath('settings.qcto_working_days', true);
    $due = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment")->json('data.due');
    expect($due['due_on'])->toBe(WorkingDays::add(CarbonImmutable::parse($this->cohort->intake_on), 21)->toDateString())->and($due['working_days'])->toBeTrue();
});

it('downloads the file as text in QCTO column order and records what was sent', function (): void {
    $learner = enrolLearner($this, '082 123 4567', '9202204720083');
    completeProfile($this, $learner);
    signInOwner($this, $this->tym, 'qcto@tym.test');
    $this->get(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/workbook")->assertStatus(422)->assertJsonValidationErrors('acknowledge'); // warnings to confirm
    $res = $this->get(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/workbook?acknowledge=1")->assertOk();
    expect($res->headers->get('Content-Disposition'))->toContain('LEISA'.now()->format('Ymd').'-Training Young Minds (Pty) Ltd.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    file_put_contents($path, $res->getContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $r) {
            $rows[] = $r->toArray();
        }
    }
    $reader->close();
    expect($rows)->toHaveCount(2)->and($rows[0][0])->toBe('SDP Code')->and($rows[0][42])->toBe('Date Stamp')
        ->and($rows[1][2])->toBe('9202204720083')->and($rows[1][25])->toBe('1804');

    $after = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/sent", ['sent_on' => now()->toDateString(), 'note' => 'Emailed'])->assertCreated()->json('data');
    expect($after['unsent'])->toBe(0)->and($after['sent'][0]['rows'])->toBe(1);

    enrolLearner($this, '082 222 3333', '9001014800089', 'Kagiso');
    signInOwner($this, $this->tym, 'qcto2@tym.test');
    expect($this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment")->json('data.unsent'))->toBe(1);
});

it('reminds the owner before the QCTO file is due, once per step', function (): void {
    enrolLearner($this, '082 123 4567', '9202204720083');
    $due = $this->cohort->intake_on->copy()->addDays(21);
    $this->travelTo($due->copy()->subDays(6));
    $this->artisan('enrolment:qcto-due')->assertSuccessful();
    $this->artisan('enrolment:qcto-due')->assertSuccessful();
    $owners = asTenant($this->tym, fn () => DB::table('role_assignments')->where('role', 'tp-owner')->count());
    Notification::assertSentOnDemandTimes(QctoEnrolmentDue::class, $owners); // one each, not again on the second run
    Notification::assertSentOnDemand(QctoEnrolmentDue::class, fn ($n) => $n->days === 6 && $n->learners === 1 && $n->notReady === 1);
    $this->travelTo($due->copy()->addDay());
    $this->artisan('enrolment:qcto-due')->assertSuccessful();
    Notification::assertSentOnDemandTimes(QctoEnrolmentDue::class, 2 * $owners);
});

it('counts South African working days around public holidays', function (): void {
    expect(WorkingDays::holidays(2027))->toContain('2027-03-22', '2027-03-26', '2027-03-29') // Human Rights Day on a Sunday, Good Friday, Family Day
        ->and(WorkingDays::add(CarbonImmutable::parse('2027-03-19'), 3)->toDateString())->toBe('2027-03-25')
        ->and(WorkingDays::add(CarbonImmutable::parse('2027-03-25'), 1)->toDateString())->toBe('2027-03-30');
});

it('checks ID numbers with Home Affairs in a nightly batch, only when connected', function (): void {
    $a = applyAs($this, '082 123 4567', '9202204720083');
    signInOwner($this, $this->tym, 'id@tym.test');
    $this->postJson(TYM_V1."/learner-applications/{$a->id}/identity-check")->assertStatus(422)->assertJsonValidationErrors('identity');

    asPlatform(fn () => Integration::query()->create(['type' => 'identity', 'driver' => 'identity.test', 'credentials' => [],
        'settings' => ['names' => '{"9202204720083": "Ayanda Precious|Zulu", "9001014800089": "Kagiso|Molefe"}', 'deceased' => '9505055800089'],
        'mode' => 'sandbox', 'enabled' => true, 'priority' => 10]));
    $this->postJson(TYM_V1."/learner-applications/{$a->id}/identity-check")->assertStatus(202)->assertJsonPath('data.status', 'queued');
    $this->postJson(TYM_V1."/learner-applications/{$a->id}/identity-check")->assertStatus(202); // still one in the queue
    $b = applyAs($this, '082 222 3333', '9001014800089', 'Sipho');
    signInOwner($this, $this->tym, 'id2@tym.test');
    $this->postJson(TYM_V1."/learner-applications/{$b->id}/identity-check")->assertStatus(202);
    expect(asTenant($this->tym, fn () => IdentityCheck::query()->count()))->toBe(2);

    $this->artisan('identity:run-checks')->assertSuccessful();
    $this->getJson(TYM_V1."/learner-applications/{$a->id}")->assertJsonPath('data.identity_check.status', 'verified');
    $mismatch = $this->getJson(TYM_V1."/learner-applications/{$b->id}")->json('data.identity_check');
    expect($mismatch['status'])->toBe('mismatch')->and(implode(' ', $mismatch['problems']))->toContain('surname Molefe')->toContain('first names Kagiso');
});

it('removes applicants who never enrolled after the retention period, and nobody else', function (): void {
    $old = applyAs($this, '082 123 4567', '9202204720083');
    $recent = applyAs($this, '082 444 5555', '9505055800089', 'Nomsa');
    $learner = enrolLearner($this, '082 222 3333', '9001014800089', 'Kagiso');
    asTenant($this->tym, function () use ($old, $learner): void {
        $old->forceFill(['status' => 'declined', 'decided_at' => now()->subMonths(13), 'submitted_at' => now()->subMonths(14)])->save();
        Application::query()->where('user_id', $learner->id)->update(['decided_at' => now()->subMonths(13), 'submitted_at' => now()->subMonths(14)]);
        LearnerDocument::query()->create(['user_id' => $old->user_id, 'file_id' => learnerFile($this->tym, $old->user_id, documentPhoto('small')), 'type' => 'cv', 'certified' => false, 'checks' => [], 'status' => 'pending']);
    });

    $this->artisan('enrolment:purge-applicants', ['--dry-run' => true])->expectsOutput('Would remove 1 applicants')->assertSuccessful();
    $this->artisan('enrolment:purge-applicants')->expectsOutput('Removed 1 applicants')->assertSuccessful();
    asTenant($this->tym, function () use ($old, $recent, $learner): void {
        expect(Application::query()->where('user_id', $old->user_id)->exists())->toBeFalse()
            ->and(LearnerDocument::query()->where('user_id', $old->user_id)->exists())->toBeFalse()
            ->and(UserTenant::query()->where('user_id', $old->user_id)->exists())->toBeFalse()
            ->and(Application::query()->whereKey($recent->id)->exists())->toBeTrue()
            ->and(Enrolment::query()->where('user_id', $learner->id)->exists())->toBeTrue();
    });
    expect(asPlatform(fn () => User::query()->whereKey($old->user_id)->exists()))->toBeTrue(); // the platform account stays
});
