<?php

declare(strict_types=1);

require_once __DIR__.'/../People/helpers.php';

use Illuminate\Testing\TestResponse;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Intake;
use Modules\Identity\Otp\LogOtpChannel;
use Modules\Qualifications\Models\Programme;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;

/** A provider with a programme, a centre and a cohort being planned. */
function enrolmentSetup(object $test): void
{
    $test->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds', 'legal_name' => 'Training Young Minds (Pty) Ltd']);
    $test->q = catalogueEntry();
    [$test->centre, $test->cohort] = asTenant($test->tym, function () use ($test) {
        Programme::query()->create(['qualification_id' => $test->q->id, 'active' => true]);
        $centre = Centre::query()->create(['name' => 'Soweto Campus', 'city' => 'Soweto', 'province' => 'Gauteng']);
        $cohort = Cohort::query()->create(['name' => 'Bricklayer February 2027', 'qualification_id' => $test->q->id, 'centre_id' => $centre->id, 'mode' => 'contact',
            'intake_on' => now()->addMonths(2), 'planned_end_on' => now()->addMonths(14), 'capacity' => 20]);
        $cohort->forceFill(['status' => 'draft'])->save();

        return [$centre, $cohort];
    });
}

/** Publish an intake as the owner and return it. */
function openIntake(object $test, array $overrides = []): Intake
{
    signInOwner($test, $test->tym);
    $id = $test->postJson(TYM_V1.'/intakes', [
        'cohort_id' => $test->cohort->id, 'title' => 'Bricklayer, February 2027', 'opens_on' => now()->toDateString(),
        'closes_on' => now()->addMonth()->toDateString(), 'min_age' => 18,
        'questions' => [['key' => 'employed', 'label' => 'Are you employed?', 'type' => 'yesno', 'required' => true],
            ['key' => 'transport', 'label' => 'How will you get to class?', 'type' => 'choice', 'options' => ['Walk', 'Taxi', 'Own car']]],
        ...$overrides,
    ])->assertCreated()->json('data.id');
    $test->postJson(TYM_V1."/intakes/{$id}/publish")->assertOk();
    $test->flushHeaders()->flushSession();

    return asTenant($test->tym, fn () => Intake::query()->findOrFail($id));
}

/** Apply without an account: PIN to the phone, then the form. */
function applyPublic(object $test, Intake $intake, array $details = [], string $phone = '082 123 4567'): TestResponse
{
    $challenge = $test->postJson(TYM_V1."/public/intakes/{$intake->slug}/code", ['phone' => $phone])->assertOk()->json('data.challenge_id');
    preg_match('/^(\d{6})/', (string) end(LogOtpChannel::$sent)['message'], $m);

    return $test->postJson(TYM_V1."/public/intakes/{$intake->slug}/apply", [
        'challenge_id' => $challenge, 'code' => $m[1], 'first_name' => 'Ayanda', 'last_name' => 'Zulu', 'id_type' => 'sa_id',
        'id_number' => '9202204720083', 'answers' => ['employed' => false, 'transport' => 'Taxi'], ...$details,
    ]);
}

function tenantOf(object $test): Tenant
{
    return $test->tym;
}
