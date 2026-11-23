<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Modules\Enrolment\Models\LearnerDocument;
use Modules\Enrolment\Support\ImageQuality;
use Modules\Identity\Models\User;

beforeEach(function (): void {
    enrolmentSetup($this);
    $this->intake = openIntake($this, ['document_types' => ['id_document', 'highest_qualification']]);
});

/** Apply in the browser: the PIN signs the applicant in, so they can add documents. */
function applyAndStay(object $test): array
{
    $test->withHeaders(webOrigin('tym.skills.test'));
    $res = applyPublic($test, $test->intake)->assertCreated()->assertJsonPath('data.signed_in', true)->json('data');
    $user = asPlatform(fn () => User::query()->where('phone_e164', '+27821234567')->sole());

    return [$res['id'], $user];
}

it('measures whether a document photo can be read', function (): void {
    expect(ImageQuality::check(documentPhoto('sharp'))['ok'])->toBeTrue()
        ->and(ImageQuality::check(documentPhoto('blurry'))['problems'][0])->toContain('blurry')
        ->and(ImageQuality::check(documentPhoto('small'))['problems'][0])->toContain('too small')
        ->and(collect(ImageQuality::check(documentPhoto('glare'))['problems'])->implode(' '))->toContain('glare')
        ->and(ImageQuality::check('not an image')['ok'])->toBeFalse();
});

it('lets an applicant add documents right after applying, with automatic checks', function (): void {
    [$applicationId, $user] = applyAndStay($this);

    $id = $this->postJson(TYM_V1.'/me/documents', ['type' => 'id_document', 'file_id' => learnerFile($this->tym, $user->id, documentPhoto('sharp')),
        'certified' => true, 'certified_on' => now()->subDays(10)->toDateString(), 'certified_by' => 'SAPS Orlando',
        'barcode_id_number' => '9202204720083'])->assertCreated()->json('data');
    expect($id['checks'])->toMatchArray(['id_match' => true, 'id_source' => 'barcode'])
        ->and($id['checks']['certification'])->toBe(['ok' => true, 'problem' => null])
        ->and($id['checks']['quality']['ok'])->toBeTrue();

    // Certified too long ago, and a blurry photo.
    $old = $this->postJson(TYM_V1.'/me/documents', ['type' => 'highest_qualification', 'file_id' => learnerFile($this->tym, $user->id, documentPhoto('blurry')),
        'certified' => true, 'certified_on' => now()->subDays(120)->toDateString()])->assertCreated()->json('data');
    expect($old['checks']['certification']['ok'])->toBeFalse()->and($old['checks']['certification']['problem'])->toContain('120 days ago')
        ->and($old['checks']['quality']['ok'])->toBeFalse();

    // A certified copy needs its date; someone else's file is refused.
    $this->postJson(TYM_V1.'/me/documents', ['type' => 'highest_qualification', 'file_id' => learnerFile($this->tym, $user->id, documentPhoto('sharp')), 'certified' => true])
        ->assertStatus(422)->assertJsonValidationErrors('certified_on');
    $stranger = makeMember($this->tym, [], ['email' => 'x@tym.test']);
    $this->postJson(TYM_V1.'/me/documents', ['type' => 'cv', 'file_id' => learnerFile($this->tym, $stranger->id, 'pdf', 'application/pdf')])
        ->assertStatus(422)->assertJsonValidationErrors('file_id');

    $needed = $this->getJson(TYM_V1.'/me/documents')->assertOk()->json('data.needed.0');
    expect($needed['complete'])->toBeFalse()->and(collect($needed['items'])->pluck('state', 'type')->all())->toBe(['id_document' => 'pending', 'highest_qualification' => 'pending']);
});

it('has staff accept or reject each document, under the provider\'s rules', function (): void {
    [$applicationId, $user] = applyAndStay($this);
    $idDoc = $this->postJson(TYM_V1.'/me/documents', ['type' => 'id_document', 'file_id' => learnerFile($this->tym, $user->id, documentPhoto('sharp')),
        'certified' => true, 'certified_on' => now()->subDays(5)->toDateString()])->json('data.id'); // uploaded on the web: no barcode
    $qual = $this->postJson(TYM_V1.'/me/documents', ['type' => 'highest_qualification', 'file_id' => learnerFile($this->tym, $user->id, documentPhoto('sharp')),
        'certified' => true, 'certified_on' => now()->subDays(100)->toDateString()])->json('data.id');

    $this->flushHeaders()->flushSession();
    signInOwner($this, $this->tym, 'owner2@tym.test');
    $detail = $this->getJson(TYM_V1."/learner-applications/{$applicationId}")->assertOk()->json('data');
    expect($detail['documents'])->toHaveCount(2)->and($detail['requirements']['complete'])->toBeFalse();

    // An ID document needs the number confirmed when no barcode was read.
    $this->postJson(TYM_V1."/learner-documents/{$idDoc}/review", ['decision' => 'accept'])->assertStatus(422)->assertJsonValidationErrors('id_confirmed');
    $this->postJson(TYM_V1."/learner-documents/{$idDoc}/review", ['decision' => 'accept', 'id_confirmed' => true])->assertOk()->assertJsonPath('data.status', 'accepted');

    // Certified 100 days ago breaks the 90-day rule; the provider allows 6 months instead.
    $this->postJson(TYM_V1."/learner-documents/{$qual}/review", ['decision' => 'accept'])->assertStatus(422)->assertJsonValidationErrors('decision');
    $this->patchJson(TYM_V1.'/enrolment-settings', ['document_rules' => ['highest_qualification' => ['max_age_days' => 180]]])->assertOk();
    $this->postJson(TYM_V1."/learner-documents/{$qual}/review", ['decision' => 'accept'])->assertOk();
    expect($this->getJson(TYM_V1."/learner-applications/{$applicationId}")->json('data.requirements.complete'))->toBeTrue();

    // Rejecting needs a reason; a rejection shows as needed again.
    $this->postJson(TYM_V1."/learner-documents/{$qual}/review", ['decision' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson(TYM_V1."/learner-documents/{$qual}/review", ['decision' => 'reject', 'reason' => 'The stamp is not readable'])->assertOk();
    $this->postJson(TYM_V1."/learner-applications/{$applicationId}/request-documents")->assertOk()->assertJsonPath('data.requested.0.type', 'highest_qualification');
});

it('ages accepted copies out under the rule, and refuses a barcode that does not match', function (): void {
    [$applicationId, $user] = applyAndStay($this);
    $this->postJson(TYM_V1.'/me/documents', ['type' => 'id_document', 'file_id' => learnerFile($this->tym, $user->id, documentPhoto('sharp')),
        'certified' => true, 'certified_on' => now()->subDays(2)->toDateString(), 'barcode_id_number' => '8001015009087'])
        ->assertCreated()->assertJsonPath('data.checks.id_match', false);
    $q = $this->postJson(TYM_V1.'/me/documents', ['type' => 'highest_qualification', 'file_id' => learnerFile($this->tym, $user->id, documentPhoto('sharp')),
        'certified' => true, 'certified_on' => now()->subDays(80)->toDateString()])->json('data.id');
    $id = $this->getJson(TYM_V1.'/me/documents')->json('data.documents.1.id');

    $this->flushHeaders()->flushSession();
    signInOwner($this, $this->tym, 'owner2@tym.test');
    $this->postJson(TYM_V1."/learner-documents/{$id}/review", ['decision' => 'accept', 'id_confirmed' => true])->assertStatus(422);
    $this->postJson(TYM_V1."/learner-documents/{$q}/review", ['decision' => 'accept'])->assertOk();
    $this->travel(20)->days(); // now 100 days since certification
    $items = collect($this->getJson(TYM_V1."/learner-applications/{$applicationId}")->json('data.requirements.items'))->keyBy('type');
    expect($items['highest_qualification']['state'])->toBe('expired');
});

it('keeps documents to the applicant and the provider\'s staff', function (): void {
    [, $user] = applyAndStay($this);
    $fileId = learnerFile($this->tym, $user->id, documentPhoto('sharp'));
    $doc = $this->postJson(TYM_V1.'/me/documents', ['type' => 'cv', 'file_id' => $fileId])->json('data.id');
    $this->getJson(TYM_V1."/files/{$fileId}")->assertOk(); // their own

    makeMember($this->tym, ['host-employer'], ['email' => 'host@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'host@tym.test');
    $this->getJson(TYM_V1."/files/{$fileId}")->assertForbidden();
    $this->postJson(TYM_V1."/learner-documents/{$doc}/review", ['decision' => 'accept'])->assertForbidden();
    expect(asTenant(makeProvider('other'), fn () => LearnerDocument::query()->count()))->toBe(0);
});
