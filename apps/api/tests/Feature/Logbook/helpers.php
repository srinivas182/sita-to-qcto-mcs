<?php

declare(strict_types=1);

require_once __DIR__.'/../Delivery/helpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Cohorts\Models\Placement;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Enrolment\Models\Enrolment;
use Modules\Logbook\Support\EntryHash;

/** A cohort with a WM module, an enrolled learner, and the Soweto yard (with Bheki) as its workplace. */
function logbookSetup(object $test): void
{
    deliverySetup($test);
    $test->intake = openIntake($test, ['document_types' => [], 'min_age' => null]);
    $test->ayanda = enrolLearner($test, '082 123 4567', '9202204720083');
    asPlatform(fn () => $test->ayanda->forceFill(['email' => 'ayanda@learner.test', 'password' => 'correct horse battery staple'])->save());
    $test->enrolment = asTenant($test->tym, fn () => Enrolment::query()->where('user_id', $test->ayanda->id)->value('id'));
    [$test->site, $test->mentor, $test->otherSite] = asTenant($test->tym, function () use ($test) {
        $e = Employer::query()->create(['legal_name' => 'Mabaso Builders (Pty) Ltd', 'registration_number' => '2015/123456/07', 'sdl_number' => 'L123456789']);
        $s = EmployerSite::query()->create(['employer_id' => $e->id, 'name' => 'Soweto yard', 'city' => 'Soweto', 'approval_number' => 'CETA/WPA/1', 'approval_body' => 'CETA',
            'approved_on' => now()->subYear(), 'approval_expires_on' => now()->addYear(), 'approved_qualification_ids' => [$test->q->id], 'latitude' => '-26.2485', 'longitude' => '27.8540']);
        $o = EmployerSite::query()->create(['employer_id' => $e->id, 'name' => 'Not on this cohort', 'approval_number' => 'CETA/WPA/2', 'approval_body' => 'CETA',
            'approved_on' => now()->subYear(), 'approval_expires_on' => now()->addYear(), 'approved_qualification_ids' => [$test->q->id]]);
        $m = Mentor::query()->create(['employer_id' => $e->id, 'name' => 'Bheki Mabaso', 'designation' => 'Foreman', 'years_experience' => 14, 'site_ids' => [$s->id]]);
        Placement::query()->create(['cohort_id' => $test->cohort->id, 'employer_id' => $e->id, 'site_id' => $s->id, 'mentor_id' => $m->id, 'planned_learners' => 5]);

        return [$s, $m, $o];
    });
    $test->today = CarbonImmutable::now('Africa/Johannesburg')->toDateString();
    signInOwner($test, $test->tym, 'office@tym.test');
}

/** Rules on the WM module and the learner placed at the Soweto yard from 30 days ago. */
function placed(object $test): void
{
    $test->putJson(TYM_V1."/modules/{$test->wm->id}/logbook-rules", ['activities' => [
        ['key' => 'mix', 'title' => 'Mix mortar'], ['key' => 'lay', 'title' => 'Lay a course', 'photo' => true],
    ]])->assertOk();
    $test->postJson(TYM_V1."/cohorts/{$test->cohort->id}/learner-placements", ['enrolment_id' => $test->enrolment, 'site_id' => $test->site->id,
        'mentor_id' => $test->mentor->id, 'starts_on' => CarbonImmutable::now()->subDays(30)->toDateString()])->assertCreated();
}

/** An entry as the phone would send it, hashed onto $prev. */
function entry(object $test, ?string $prev, array $over = []): array
{
    $e = array_merge(['client_id' => (string) Str::uuid(), 'module_id' => $test->wm->id, 'activity_key' => 'mix', 'work_date' => $test->today, 'minutes' => 240,
        'note' => 'Mixed mortar for the east wall', 'photo_hashes' => [], 'device_time' => CarbonImmutable::now('Africa/Johannesburg')->format('Y-m-d\TH:i:sP')], $over);
    $e['prev_hash'] = $prev;
    $e['hash'] = EntryHash::make($prev, $e['client_id'], $e['module_id'], $e['activity_key'], $e['work_date'], $e['minutes'], $e['note'], $e['photo_hashes'], $e['device_time']);

    return $e;
}
