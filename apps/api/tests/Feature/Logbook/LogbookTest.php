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
use Modules\Files\Models\File;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Support\EntryHash;

beforeEach(function (): void {
    deliverySetup($this);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $this->ayanda = enrolLearner($this, '082 123 4567', '9202204720083');
    asPlatform(fn () => $this->ayanda->forceFill(['email' => 'ayanda@learner.test', 'password' => 'correct horse battery staple'])->save());
    $this->enrolment = asTenant($this->tym, fn () => Enrolment::query()->where('user_id', $this->ayanda->id)->value('id'));
    [$this->site, $this->mentor, $this->otherSite] = asTenant($this->tym, function () {
        $e = Employer::query()->create(['legal_name' => 'Mabaso Builders (Pty) Ltd', 'registration_number' => '2015/123456/07', 'sdl_number' => 'L123456789']);
        $s = EmployerSite::query()->create(['employer_id' => $e->id, 'name' => 'Soweto yard', 'city' => 'Soweto', 'approval_number' => 'CETA/WPA/1', 'approval_body' => 'CETA',
            'approved_on' => now()->subYear(), 'approval_expires_on' => now()->addYear(), 'approved_qualification_ids' => [$this->q->id], 'latitude' => '-26.2485', 'longitude' => '27.8540']);
        $o = EmployerSite::query()->create(['employer_id' => $e->id, 'name' => 'Not on this cohort', 'approval_number' => 'CETA/WPA/2', 'approval_body' => 'CETA',
            'approved_on' => now()->subYear(), 'approval_expires_on' => now()->addYear(), 'approved_qualification_ids' => [$this->q->id]]);
        $m = Mentor::query()->create(['employer_id' => $e->id, 'name' => 'Bheki Mabaso', 'designation' => 'Foreman', 'years_experience' => 14, 'site_ids' => [$s->id]]);
        Placement::query()->create(['cohort_id' => $this->cohort->id, 'employer_id' => $e->id, 'site_id' => $s->id, 'mentor_id' => $m->id, 'planned_learners' => 5]);

        return [$s, $m, $o];
    });
    $this->today = CarbonImmutable::now('Africa/Johannesburg')->toDateString();
    signInOwner($this, $this->tym, 'office@tym.test');
});

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

it('hashes an entry the same way as the phone', function (): void {
    expect(EntryHash::make(null, '6f1c7a0e-9b1e-4f1e-8d63-0a1b2c3d4e5f', '01jdemomodule0000000000000', 'mix', '2027-02-03', 240, 'Mixed mortar | laid 2 courses', ['AB12'], '2027-02-03T14:05:00+02:00'))
        ->toBe('8f29e3c78e81e054144028305cab0f8f7debad2ee11c94f239552d49188f722c');
});

it('sets logbook rules on work experience modules only, defaulting hours from credits', function (): void {
    $this->putJson(TYM_V1."/modules/{$this->km->id}/logbook-rules", ['activities' => [['title' => 'Read']]])->assertStatus(422)->assertJsonValidationErrors('module');
    $this->putJson(TYM_V1."/modules/{$this->wm->id}/logbook-rules", ['activities' => [['title' => 'Mix mortar'], ['title' => 'Lay a course', 'photo' => true]]])
        ->assertOk()->assertJsonPath('data.hours_needed', 400)->assertJsonPath('data.activities.1.key', 'a2');
    $this->getJson(TYM_V1.'/logbook-rules?qualification_id='.$this->q->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rule.hours_needed', 400);
    $this->getJson(TYM_V1."/modules/{$this->wm->id}/logbook-rules")->assertOk()->assertJsonPath('data.default_hours', 400)->assertJsonPath('data.rule.activities.0.title', 'Mix mortar');
});

it('places learners only at the cohort\'s workplaces with a mentor there, without overlaps', function (): void {
    $start = CarbonImmutable::now()->subDays(30)->toDateString();
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/learner-placements", ['enrolment_id' => $this->enrolment, 'site_id' => $this->otherSite->id, 'starts_on' => $start])
        ->assertStatus(422)->assertJsonValidationErrors('site_id');
    $id = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/learner-placements", ['enrolment_id' => $this->enrolment, 'site_id' => $this->site->id, 'mentor_id' => $this->mentor->id, 'starts_on' => $start])
        ->assertCreated()->json('data.id');
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/learner-placements", ['enrolment_id' => $this->enrolment, 'site_id' => $this->site->id, 'starts_on' => $this->today])
        ->assertStatus(422)->assertJsonValidationErrors('starts_on');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/learner-placements")->assertOk()->assertJsonPath('data.0.site', 'Soweto yard')->assertJsonPath('data.0.mentor', 'Bheki Mabaso')
        ->assertJsonPath('options.sites.0.name', 'Soweto yard');
    $this->postJson(TYM_V1."/learner-placements/{$id}/end", ['ends_on' => CarbonImmutable::now()->subDays(31)->toDateString()])->assertStatus(422);
    $this->postJson(TYM_V1."/learner-placements/{$id}/end", ['ends_on' => $this->today])->assertOk();
});

it('takes chained entries from the learner, once per client id, checking the hash and the day', function (): void {
    placed($this);
    signInAs($this, 'ayanda@learner.test');
    $this->getJson(TYM_V1.'/me/logbook')->assertOk()->assertJsonPath('data.modules.0.code', 'WM-01')->assertJsonPath('data.last_hash', null);

    $first = entry($this, null, ['latitude' => -26.2487, 'longitude' => 27.8541, 'accuracy' => 10, 'offline' => true]);
    $tampered = array_merge($first, ['minutes' => 480]);
    $this->postJson(TYM_V1.'/me/logbook', $tampered)->assertStatus(422)->assertJsonValidationErrors('hash');
    $a = $this->postJson(TYM_V1.'/me/logbook', $first)->assertCreated()->json('data');
    expect($a)->toMatchArray(['seq' => 1, 'location_ok' => true, 'offline' => true, 'rechained' => false])->and($a['distance_m'])->toBeLessThan(50);
    $this->postJson(TYM_V1.'/me/logbook', $first)->assertOk()->assertJsonPath('data.id', $a['id']); // outbox retry

    // The phone was behind (two entries written offline against an old last hash): the server links it on.
    $b = $this->postJson(TYM_V1.'/me/logbook', entry($this, null, ['minutes' => 120]))->assertCreated()->json('data');
    expect($b)->toMatchArray(['seq' => 2, 'rechained' => true]);

    $this->postJson(TYM_V1.'/me/logbook', entry($this, $b['hash'], ['minutes' => 600]))->assertStatus(422)->assertJsonValidationErrors('minutes'); // over 14 h
    $this->postJson(TYM_V1.'/me/logbook', entry($this, $b['hash'], ['work_date' => CarbonImmutable::now()->addDays(2)->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('work_date');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, $b['hash'], ['work_date' => CarbonImmutable::now()->subDays(60)->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('work_date');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, $b['hash'], ['module_id' => $this->pm->id]))->assertStatus(422)->assertJsonValidationErrors('module_id');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, $b['hash'], ['activity_key' => 'paint']))->assertStatus(422)->assertJsonValidationErrors('activity_key');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, $b['hash'], ['activity_key' => 'lay']))->assertStatus(422)->assertJsonValidationErrors('file_ids');

    $this->getJson(TYM_V1.'/me/logbook')->assertOk()->assertJsonPath('data.hours.0.hours', 6)->assertJsonPath('data.last_hash', $b['hash']);
});

it('needs the photo taken with the entry for photo activities', function (): void {
    placed($this);
    $hash = hash('sha256', 'photo');
    $photo = asTenant($this->tym, fn () => File::query()->create(['owner_id' => $this->ayanda->id, 'purpose' => 'logbook_photo', 'original_name' => 'wall.jpg', 'mime' => 'image/jpeg',
        'size' => 10, 'store_driver' => 'local', 'path' => 'x/'.Str::ulid().'.jpg', 'status' => 'ready', 'sha256' => $hash])->id);
    signInAs($this, 'ayanda@learner.test');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, null, ['activity_key' => 'lay', 'file_ids' => [$photo], 'photo_hashes' => [hash('sha256', 'other')]]))
        ->assertStatus(422)->assertJsonValidationErrors('photo_hashes');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, null, ['activity_key' => 'lay', 'file_ids' => [$photo], 'photo_hashes' => [strtoupper($hash)]]))->assertCreated();
});

it('corrects an entry with a new one, keeps both in the chain, and the provider sees hours and a sound chain', function (): void {
    placed($this);
    signInAs($this, 'ayanda@learner.test');
    $a = $this->postJson(TYM_V1.'/me/logbook', entry($this, null))->assertCreated()->json('data');
    $fix = entry($this, $a['hash'], ['minutes' => 180, 'note' => 'Mixed mortar (3 h, not 4)']);
    $b = $this->postJson(TYM_V1."/me/logbook/{$a['id']}/correct", $fix)->assertCreated()->json('data');
    expect($b)->toMatchArray(['replaces_id' => $a['id'], 'seq' => 2, 'minutes' => 180]);
    $this->postJson(TYM_V1."/me/logbook/{$a['id']}/correct", entry($this, $b['hash']))->assertStatus(422)->assertJsonValidationErrors('entry'); // already corrected

    signInOwner($this, $this->tym, 'office2@tym.test');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/logbook")->assertOk()->assertJsonPath('data.0.hours.0.hours', 3)
        ->assertJsonPath('data.0.chain', ['ok' => true, 'entries' => 2, 'broken_at' => null])->assertJsonPath('data.0.last_entry', $this->today);

    // Change a stored entry behind the system's back: the chain check shows it.
    asTenant($this->tym, fn () => LogbookEntry::query()->whereKey($b['id'])->update(['minutes' => 600]));
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/logbook/{$this->ayanda->id}")->assertOk()->assertJsonPath('data.chain.ok', false)->assertJsonPath('data.chain.broken_at', 2);
});

it('keeps logbooks to the learner and the provider', function (): void {
    placed($this);
    signInAs($this, 'ayanda@learner.test');
    $a = $this->postJson(TYM_V1.'/me/logbook', entry($this, null))->assertCreated()->json('data');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/logbook")->assertForbidden();
    $this->putJson(TYM_V1."/modules/{$this->wm->id}/logbook-rules", ['activities' => [['title' => 'x']]])->assertForbidden();

    $lerato = enrolLearner($this, '082 222 3333', '9001014800089');
    asPlatform(fn () => $lerato->forceFill(['email' => 'lerato@learner.test', 'password' => 'correct horse battery staple'])->save());
    signInAs($this, 'lerato@learner.test');
    $this->postJson(TYM_V1."/me/logbook/{$a['id']}/correct", entry($this, null))->assertNotFound();
    $this->postJson(TYM_V1.'/me/logbook', entry($this, null))->assertStatus(422)->assertJsonValidationErrors('work_date'); // not placed
});
