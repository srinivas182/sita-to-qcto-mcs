<?php

declare(strict_types=1);

require_once __DIR__.'/../Billing/helpers.php';

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\Suggestion;
use Modules\Qualifications\Notifications\EnrolmentClosing;
use Modules\Qualifications\Notifications\SuggestionDecided;
use Modules\Tenancy\Models\Centre;

const ADMIN = 'https://admin.skills.test/v1/admin';
const TYM = 'https://tym.skills.test/v1';

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
});

/** An occupational qualification with KM/PM/WM modules, published by a catalogue editor. */
function publishedOccupational(object $test, array $extra = []): string
{
    signInStaff($test, 'jv-compliance', 'catalogue@tym-mcs.co.za');
    $id = $test->postJson(ADMIN.'/qualifications', $extra + [
        'type' => 'trade', 'saqa_id' => '93627', 'title' => 'Occupational Certificate: Bricklayer', 'nqf_level' => 4, 'credits' => 30,
        'quality_partner' => 'CETA', 'assessment_partner' => 'NAMB', 'eisa' => true,
    ])->assertCreated()->json('data.id');
    $test->postJson(ADMIN."/qualifications/{$id}/modules", ['apply' => true, 'text' => "641201001-KM-01 Industry contexts NQF 4 10\n641201001-PM-01 Lay bricks to line and level NQF 4 10\n641201001-WM-01 Laying bricks on site NQF 4 10"])
        ->assertOk()->assertJsonPath('applied', true);
    $test->postJson(ADMIN."/qualifications/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

    return $id;
}

it('lets catalogue editors build, check and publish a qualification', function (): void {
    signInStaff($this, 'jv-compliance', 'catalogue@tym-mcs.co.za');
    $id = $this->postJson(ADMIN.'/qualifications', ['type' => 'occupational_certificate', 'title' => 'Occupational Certificate: Bricklayer', 'nqf_level' => 4, 'credits' => 30])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    // Nothing to publish yet.
    $this->postJson(ADMIN."/qualifications/{$id}/publish")->assertStatus(422)->assertJsonValidationErrors('qualification');

    // Preview first: a bad line blocks saving and is explained.
    $preview = $this->postJson(ADMIN."/qualifications/{$id}/modules", ['text' => "641201001-KM-01 Industry contexts NQF 4 10\n13973 | A unit standard | 1 | 4"])
        ->assertOk()->assertJsonPath('applied', false);
    expect($preview->json('errors.0.line'))->toBe(2)->and($preview->json('rows'))->toHaveCount(1);

    $this->patchJson(ADMIN."/qualifications/{$id}", ['saqa_id' => '93627'])->assertOk();
    $this->postJson(ADMIN."/qualifications/{$id}/modules", ['apply' => true, 'text' => "641201001-KM-01 Industry contexts NQF 4 10\n641201001-PM-01 Lay bricks NQF 4 10"])->assertOk();
    $detail = $this->getJson(ADMIN."/qualifications/{$id}")->assertOk()->json('data');
    expect($detail['modules'])->toHaveCount(2)
        ->and($detail['checks']['warnings'])->toContain('No work experience modules: occupational qualifications normally have all three.')
        ->and($detail['checks']['warnings'][1])->toContain('20 credits');

    $this->postJson(ADMIN."/qualifications/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->getJson(ADMIN.'/qualifications?status=published')->assertJsonPath('counts.published', 1);

    // Editing a published entry bumps its version.
    $this->patchJson(ADMIN."/qualifications/{$id}", ['last_enrolment_on' => '2026-12-30'])->assertOk()->assertJsonPath('data.version', 2);
});

it('checks legacy unit-standard credit rules and needs a last enrolment date', function (): void {
    signInStaff($this, 'jv-compliance', 'catalogue@tym-mcs.co.za');
    $id = $this->postJson(ADMIN.'/qualifications', [
        'type' => 'legacy_qualification', 'saqa_id' => '65409', 'title' => 'National Certificate: Building and Civil Construction',
        'nqf_level' => 3, 'credits' => 20, 'credit_rules' => ['fundamental' => 6, 'core' => 8, 'elective_min' => 6],
    ])->assertCreated()->json('data.id');
    $this->postJson(ADMIN."/qualifications/{$id}/modules", ['apply' => true, 'text' => "12462 | Engage in speaking and listening | 1 | 6 | fundamental\n13973 | Protective clothing and equipment | 1 | 4 | core\n119073 | Build masonry superstructures | 1 | 12 | elective"])->assertOk();

    $errors = $this->postJson(ADMIN."/qualifications/{$id}/publish")->assertStatus(422)->json('errors.qualification');
    expect($errors)->toContain('Legacy qualifications need their last date for enrolment.')
        ->and($errors)->toContain('Core unit standards add up to 4 credits; the rules need 8.');
});

it('shows providers only published entries and never lets them write the catalogue', function (): void {
    $published = publishedOccupational($this);
    $draft = asPlatform(fn () => Qualification::query()->create(['type' => 'skills_programme', 'title' => 'Draft skills programme', 'nqf_level' => 2, 'credits' => 10])->id);

    asTenant($this->tym, function () use ($published, $draft): void {
        expect(Qualification::query()->pluck('id')->all())->toBe([$published])
            ->and(Qualification::query()->whereKey($draft)->exists())->toBeFalse();
        // Row-level security hides catalogue rows from provider writes, even raw SQL.
        expect(DB::table('qualifications')->where('id', $published)->update(['title' => 'Hacked']))->toBe(0);
        expect(fn () => DB::transaction(fn () => DB::table('qualifications')->insert(['id' => (string) Str::ulid(), 'type' => 'trade', 'title' => 'Fake', 'nqf_level' => 1, 'credits' => 1, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()])))
            ->toThrow(QueryException::class);
    });
    expect(asTenant($this->tym, fn () => DB::table('qualifications')->where('id', $published)->delete()))->toBe(0);
    expect(asPlatform(fn () => Qualification::query()->findOrFail($published)->title))->toBe('Occupational Certificate: Bricklayer');

    signInOwner($this, $this->tym);
    $this->getJson(TYM.'/catalogue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.offered', false);
    $this->getJson(TYM.'/catalogue/'.$draft)->assertNotFound();
    $this->getJson(ADMIN.'/qualifications')->assertStatus(403);
});

it('lets a provider offer a qualification and links it to centres from its accreditation letter', function (): void {
    $id = publishedOccupational($this);
    [$soweto, $durban] = asTenant($this->tym, fn () => [
        Centre::query()->create(['name' => 'Soweto Campus', 'scope' => ['93627', '101869']]),
        Centre::query()->create(['name' => 'Durban Site']),
    ]);

    signInOwner($this, $this->tym);
    $this->getJson(TYM.'/programmes')->assertOk()->assertJsonPath('suggested.0.saqa_id', '93627');
    $this->postJson(TYM.'/programmes', ['qualification_id' => $id, 'modes' => ['contact', 'workplace'], 'price_cents' => 2500000])->assertCreated();
    $this->postJson(TYM.'/programmes', ['qualification_id' => $id])->assertStatus(422);

    $list = $this->getJson(TYM.'/programmes')->assertOk();
    expect($list->json('suggested'))->toBe([])->and($list->json('data.0.centres.0.name'))->toBe('Soweto Campus');

    // Scope can only hold offered qualifications.
    $other = asPlatform(fn () => Qualification::query()->create(['type' => 'skills_programme', 'saqa_id' => '11111', 'title' => 'Another skills programme', 'nqf_level' => 2, 'credits' => 10]))->id;
    $this->putJson(TYM."/centres/{$durban->id}/qualifications", ['items' => [['qualification_id' => $other]]])->assertStatus(422);
    $this->putJson(TYM."/centres/{$durban->id}/qualifications", ['items' => [['qualification_id' => $id, 'expires_on' => '2028-06-30']]])
        ->assertOk()->assertJsonPath('data.0.expires_on', '2028-06-30');
});

it('refuses withdrawn and ended qualifications', function (): void {
    $id = publishedOccupational($this, ['last_enrolment_on' => '2020-01-01', 'last_achievement_on' => '2021-01-01']);
    signInOwner($this, $this->tym);
    $this->postJson(TYM.'/programmes', ['qualification_id' => $id])->assertStatus(422)->assertJsonValidationErrors('qualification_id');
    expect($this->getJson(TYM.'/catalogue/'.$id)->json('data.enrolment'))->toBe('ended');
});

it('takes suggestions from providers and answers them', function (): void {
    signInOwner($this, $this->tym);
    $this->postJson(TYM.'/catalogue-suggestions', ['kind' => 'new', 'saqa_id' => '120663', 'title' => "Elementary Occupational Certificate: Builder's Worker", 'details' => 'We are accredited for this and would like to offer it.'])->assertCreated();

    signInStaff($this, 'jv-compliance', 'catalogue@tym-mcs.co.za');
    $s = $this->getJson(ADMIN.'/catalogue-suggestions')->assertOk()->assertJsonPath('data.0.provider', 'Training Young Minds')->json('data.0.id');
    $this->postJson(ADMIN."/catalogue-suggestions/{$s}/decide", ['accept' => true, 'response' => 'Added with its modules from the QCTO curriculum.'])->assertOk();
    $this->postJson(ADMIN."/catalogue-suggestions/{$s}/decide", ['accept' => false, 'response' => 'Again'])->assertStatus(422);
    Notification::assertSentOnDemand(SuggestionDecided::class, fn ($n) => $n->accepted === true);
    expect(asTenant($this->tym, fn () => Suggestion::query()->sole()->status))->toBe('accepted');
});

it('warns providers 180, 90 and 30 days before enrolment closes, naming the replacements', function (): void {
    $successor = publishedOccupational($this, ['saqa_id' => '126058', 'title' => 'National Occupational Certificate: Civil Services Construction Constructor']);
    $legacy = asPlatform(function () use ($successor): string {
        $q = Qualification::query()->create([
            'type' => 'legacy_qualification', 'saqa_id' => '65409', 'title' => 'National Certificate: Building and Civil Construction', 'nqf_level' => 3,
            'credits' => 140, 'last_enrolment_on' => now()->addDays(100), 'last_achievement_on' => now()->addYears(3), 'successor_ids' => [$successor],
        ]);
        $q->forceFill(['status' => 'published'])->save();

        return $q->id;
    });
    makeMember($this->tym, ['tp-owner'], ['email' => 'owner2@tym.test']);
    asTenant($this->tym, fn () => Programme::query()->create(['qualification_id' => $legacy]));

    Artisan::call('qualifications:enrolment-alerts');
    Notification::assertSentOnDemand(EnrolmentClosing::class, fn ($n) => $n->days === 100 && str_contains($n->successors[0], 'Civil Services'));
    $count = count(Notification::sentNotifications());
    Artisan::call('qualifications:enrolment-alerts');
    expect(count(Notification::sentNotifications()))->toBe($count); // once per threshold

    $this->travel(20)->days();
    Artisan::call('qualifications:enrolment-alerts');
    Notification::assertSentOnDemand(EnrolmentClosing::class, fn ($n) => $n->days === 80);
    expect(asTenant($this->tym, fn () => Programme::query()->sole()->alerted_for))->toBe(90);
});
