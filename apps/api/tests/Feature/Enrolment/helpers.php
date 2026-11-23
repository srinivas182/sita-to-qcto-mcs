<?php

declare(strict_types=1);

require_once __DIR__.'/../People/helpers.php';

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Intake;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
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

/** A JPEG for the quality check: 'sharp' (fine detail), 'blurry', 'small' or 'glare'. */
function documentPhoto(string $kind): string
{
    [$w, $h] = $kind === 'small' ? [400, 260] : [1400, 900];
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 235, 232, 225));
    $ink = imagecolorallocate($img, 25, 25, 30);
    mt_srand(7);
    if ($kind === 'glare') {
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 255, 255, 255));
    }
    // Lines of "text": small dark marks, the detail a readable document has.
    for ($y = 60; $y < $h - 40; $y += 28) {
        for ($x = 60; $x < $w - 60; $x += mt_rand(9, 16)) {
            imagefilledrectangle($img, $x, $y, $x + mt_rand(3, 8), $y + mt_rand(8, 14), $ink);
        }
    }
    if ($kind === 'blurry') { // shrink and enlarge again: detail is lost, as in a shaky photo
        $tiny = imagecreatetruecolor(intdiv($w, 24), intdiv($h, 24));
        imagecopyresampled($tiny, $img, 0, 0, 0, 0, intdiv($w, 24), intdiv($h, 24), $w, $h);
        imagecopyresampled($img, $tiny, 0, 0, 0, 0, $w, $h, intdiv($w, 24), intdiv($h, 24));
        for ($i = 0; $i < 3; $i++) {
            imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
        }
    }
    ob_start();
    imagejpeg($img, null, 90);

    return (string) ob_get_clean();
}

/** Store bytes as the person's uploaded learner document and return the file id. */
function learnerFile(Tenant $tenant, string $ownerId, string $bytes, string $mime = 'image/jpeg'): string
{
    $path = 'test/'.Str::ulid().($mime === 'application/pdf' ? '.pdf' : '.jpg');
    app(StoreFactory::class)->current()[0]->disk()->put($path, $bytes);

    return asTenant($tenant, fn () => File::query()->create([
        'owner_id' => $ownerId, 'purpose' => 'learner_document', 'original_name' => basename($path), 'mime' => $mime,
        'size' => strlen($bytes), 'store_driver' => 'local', 'path' => $path, 'status' => 'ready',
    ])->id);
}
