<?php

declare(strict_types=1);

require_once __DIR__.'/../Enrolment/helpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Delivery\Models\Room;
use Modules\People\Models\StaffCredential;
use Modules\Qualifications\Models\QualificationModule;

/** A cohort with curriculum modules, two rooms and two registered facilitators on it. */
function deliverySetup(object $test): void
{
    enrolmentSetup($test);
    [$test->km, $test->pm, $test->wm] = asPlatform(fn () => [
        QualificationModule::query()->create(['qualification_id' => $test->q->id, 'kind' => 'KM', 'group' => 'compulsory', 'code' => 'KM-01', 'title' => 'Building materials', 'credits' => 4, 'sort' => 1]),
        QualificationModule::query()->create(['qualification_id' => $test->q->id, 'kind' => 'PM', 'group' => 'compulsory', 'code' => 'PM-01', 'title' => 'Lay a brick wall', 'credits' => 8, 'sort' => 2]),
        QualificationModule::query()->create(['qualification_id' => $test->q->id, 'kind' => 'WM', 'group' => 'compulsory', 'code' => 'WM-01', 'title' => 'Bricklaying on site', 'credits' => 40, 'sort' => 3]),
    ]);
    $test->sipho = makeMember($test->tym, ['facilitator'], ['email' => 'sipho@tym.test', 'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'password' => 'correct horse battery staple']);
    $test->thabo = makeMember($test->tym, ['facilitator'], ['email' => 'thabo@tym.test', 'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'password' => 'correct horse battery staple']);
    [$test->roomA, $test->roomB] = asTenant($test->tym, function () use ($test) {
        foreach ([$test->sipho, $test->thabo] as $u) {
            StaffCredential::query()->create(['user_id' => $u->id, 'role' => 'facilitator', 'body' => 'CETA', 'registration_number' => 'F-'.Str::random(4),
                'qualification_ids' => [$test->q->id], 'starts_on' => now()->subYear(), 'expires_on' => now()->addYears(3)])->forceFill(['status' => 'verified'])->save();
            CohortStaff::query()->create(['cohort_id' => $test->cohort->id, 'user_id' => $u->id, 'role' => 'facilitator']);
        }

        return [Room::query()->create(['centre_id' => $test->centre->id, 'name' => 'Room 1', 'capacity' => 25]),
            Room::query()->create(['centre_id' => $test->centre->id, 'name' => 'Workshop', 'kind' => 'workshop'])];
    });
}

/** The date of the cohort's first Monday, as Y-m-d. */
function firstMonday(object $test): string
{
    return CarbonImmutable::parse($test->cohort->intake_on)->next('Monday')->toDateString();
}
