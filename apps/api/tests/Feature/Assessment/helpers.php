<?php

declare(strict_types=1);

require_once __DIR__.'/../Logbook/helpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\People\Models\StaffCredential;

/** A cohort with KM/PM/WM modules, an enrolled learner, a registered assessor (Naledi) and a registered moderator (Zanele). */
function assessmentSetup(object $test): void
{
    logbookSetup($test);
    $test->naledi = makeMember($test->tym, [['assessor', $test->centre->id]], ['email' => 'naledi@tym.test', 'first_name' => 'Naledi', 'last_name' => 'Mthembu', 'password' => 'correct horse battery staple']);
    $test->zanele = makeMember($test->tym, [['assessor', $test->centre->id], ['moderator', $test->centre->id]], ['email' => 'zanele@tym.test', 'first_name' => 'Zanele', 'last_name' => 'Khoza', 'password' => 'correct horse battery staple']);
    asTenant($test->tym, function () use ($test) {
        foreach ([[$test->naledi, 'assessor'], [$test->zanele, 'assessor'], [$test->zanele, 'moderator']] as [$u, $role]) {
            StaffCredential::query()->create(['user_id' => $u->id, 'role' => $role, 'body' => 'CETA', 'registration_number' => strtoupper($role).'-'.Str::random(4),
                'qualification_ids' => [$test->q->id], 'starts_on' => now()->subYear(), 'expires_on' => now()->addYears(3)])->forceFill(['status' => 'verified'])->save();
        }
    });
    $test->today = CarbonImmutable::now('Africa/Johannesburg')->toDateString();
    // The owner (signed in by logbookSetup) sets one test for KM and one observation for PM.
    $test->kmTest = $test->putJson(TYM_V1."/modules/{$test->km->id}/instruments", ['instruments' => [
        ['method' => 'test', 'title' => 'Materials test', 'pass_mark' => 60, 'criteria' => [['key' => 'k1', 'text' => 'Identify bricks, blocks and mortar types']]],
    ]])->assertOk()->json('data.0');
    $test->pmObs = $test->putJson(TYM_V1."/modules/{$test->pm->id}/instruments", ['instruments' => [
        ['method' => 'observation', 'title' => 'Build a one-brick wall', 'criteria' => [['key' => 'plumb', 'text' => 'Wall is plumb'], ['key' => 'bond', 'text' => 'Stretcher bond kept'], ['key' => 'safe', 'text' => 'Works safely']]],
    ]])->assertOk()->json('data.0');
}

/** An observation decision as an assessor would send it. */
function observation(object $test, bool $allMet, array $over = []): array
{
    return array_merge(['enrolment_id' => $test->enrolment, 'instrument_id' => $test->pmObs['id'], 'outcome' => $allMet ? 'competent' : 'nyc', 'assessed_on' => $test->today,
        'criteria' => [['key' => 'plumb', 'met' => true], ['key' => 'bond', 'met' => $allMet, 'comment' => $allMet ? null : 'Bond broken on course 3'], ['key' => 'safe', 'met' => true]],
        'feedback' => $allMet ? 'Neat work' : 'Keep the bond: check each course against the line'], $over);
}
