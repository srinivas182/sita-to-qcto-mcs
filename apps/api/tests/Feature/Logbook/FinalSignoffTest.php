<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Carbon\CarbonImmutable;
use Modules\Employers\Models\Mentor;
use Modules\Enrolment\Models\Enrolment;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Models\LogbookWeek;

beforeEach(function (): void {
    logbookSetup($this);
    placed($this);
    $this->putJson(TYM_V1."/modules/{$this->wm->id}/logbook-rules", ['activities' => [['key' => 'mix', 'title' => 'Mix mortar'], ['key' => 'lay', 'title' => 'Lay a course', 'photo' => true]],
        'hours_needed' => 8])->assertOk();
    makeMember($this->tym, [['assessor', $this->centre->id]], ['email' => 'naledi@tym.test', 'password' => 'correct horse battery staple']);
    $this->bheki = makeMember($this->tym, ['host-employer'], ['email' => 'bheki@mabaso.test', 'first_name' => 'Bheki', 'last_name' => 'Mabaso', 'password' => 'correct horse battery staple']);
    asTenant($this->tym, fn () => Mentor::query()->whereKey($this->mentor->id)->update(['user_id' => $this->bheki->id]));
    $this->monday = CarbonImmutable::now('Africa/Johannesburg')->startOfWeek()->subWeek();
});

/** Two entries last week (4 h and 5 h), sent by the learner. */
function logWeek(object $test): void
{
    signInAs($test, 'ayanda@learner.test');
    $a = $test->postJson(TYM_V1.'/me/logbook', entry($test, null, ['work_date' => $test->monday->toDateString()]))->assertCreated()->json('data');
    $test->postJson(TYM_V1.'/me/logbook', entry($test, $a['hash'], ['work_date' => $test->monday->addDay()->toDateString(), 'minutes' => 300]))->assertCreated();
}

it('has the mentor sign off a module with the authenticity statement once the weeks are signed and the hours reached', function (): void {
    logWeek($this);
    signInAs($this, 'bheki@mabaso.test');
    $body = ['enrolment_id' => $this->enrolment, 'module_id' => $this->wm->id, 'authentic' => true];
    $this->postJson(TYM_V1.'/logbook-signoffs', $body)->assertStatus(422)->assertJsonPath('errors.module_id.0', 'Sign or send back the open weeks first.');
    $weeks = $this->getJson(TYM_V1.'/mentoring/weeks')->assertOk()->json('data');
    expect($weeks['statement'])->toContain('under my supervision')->and($weeks['learners'][0]['modules'][0])->toMatchArray(['code' => 'WM-01', 'hours' => 9, 'signoff' => null]);
    $this->postJson(TYM_V1."/logbook-weeks/{$weeks['weeks'][0]['id']}/sign", ['confirm' => true])->assertOk();
    $this->postJson(TYM_V1.'/logbook-signoffs', ['authentic' => false] + $body)->assertStatus(422)->assertJsonValidationErrors('authentic');
    $id = $this->postJson(TYM_V1.'/logbook-signoffs', $body + ['comment' => 'Hard worker'])->assertCreated()->assertJsonPath('data.hours', 9)->json('data.id');
    $this->postJson(TYM_V1.'/logbook-signoffs', $body)->assertStatus(422)->assertJsonValidationErrors('module_id');

    // The provider's assessor checks it; the Statement of Work Experience data is complete.
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1."/logbook-signoffs/{$id}/check", ['outcome' => 'queried'])->assertStatus(422)->assertJsonValidationErrors('comment');
    $this->postJson(TYM_V1."/logbook-signoffs/{$id}/check", ['outcome' => 'checked'])->assertOk()->assertJsonPath('data.status', 'checked');
    signInOwner($this, $this->tym, 'office2@tym.test');
    $s = $this->getJson(TYM_V1."/enrolments/{$this->enrolment}/work-experience")->assertOk()->json('data');
    expect($s['workplaces'][0])->toMatchArray(['site' => 'Soweto yard', 'mentor' => 'Bheki Mabaso', 'approval_number' => 'CETA/WPA/1'])
        ->and($s['modules'][0])->toMatchArray(['code' => 'WM-01', 'complete' => true])->and($s['modules'][0]['signoff']['mentor'])->toBe('Bheki Mabaso')
        ->and($s['chain']['ok'])->toBeTrue();
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/logbook")->assertJsonPath('data.0.signoffs.0.status', 'checked');

    // The learner can see their own statement data.
    signInAs($this, 'ayanda@learner.test');
    $this->getJson(TYM_V1."/enrolments/{$this->enrolment}/work-experience")->assertOk();
});

it('refuses a sign-off short of the hours, and lets the mentor sign again after a query', function (): void {
    logWeek($this);
    signInOwner($this, $this->tym, 'office2@tym.test');
    $this->putJson(TYM_V1."/modules/{$this->wm->id}/logbook-rules", ['activities' => [['key' => 'mix', 'title' => 'Mix mortar'], ['key' => 'lay', 'title' => 'Lay a course', 'photo' => true]],
        'hours_needed' => 10])->assertOk();
    signInAs($this, 'bheki@mabaso.test');
    $this->postJson(TYM_V1.'/logbook-weeks/'.$this->getJson(TYM_V1.'/mentoring/weeks')->json('data.weeks.0.id').'/sign', ['confirm' => true])->assertOk();
    $body = ['enrolment_id' => $this->enrolment, 'module_id' => $this->wm->id, 'authentic' => true];
    $this->postJson(TYM_V1.'/logbook-signoffs', $body)->assertStatus(422)->assertJsonPath('errors.module_id.0', 'The learner has 9 of the 10 hours needed.');

    signInOwner($this, $this->tym, 'office3@tym.test');
    $this->putJson(TYM_V1."/modules/{$this->wm->id}/logbook-rules", ['activities' => [['key' => 'mix', 'title' => 'Mix mortar'], ['key' => 'lay', 'title' => 'Lay a course', 'photo' => true]],
        'hours_needed' => 9])->assertOk();
    signInAs($this, 'bheki@mabaso.test');
    $id = $this->postJson(TYM_V1.'/logbook-signoffs', $body)->assertCreated()->json('data.id');
    // An entry changed after the sign-off: the assessor can only query it.
    asTenant($this->tym, fn () => LogbookEntry::query()->where('user_id', $this->ayanda->id)->orderBy('seq')->first()?->forceFill(['hash' => str_repeat('a', 64)])->save());
    signInAs($this, 'naledi@tym.test');
    $this->postJson(TYM_V1."/logbook-signoffs/{$id}/check", ['outcome' => 'checked'])->assertStatus(422)->assertJsonValidationErrors('outcome');
    $this->postJson(TYM_V1."/logbook-signoffs/{$id}/check", ['outcome' => 'queried', 'comment' => 'Entry 1 changed after signing'])->assertOk();
    signInAs($this, 'bheki@mabaso.test');
    $this->postJson(TYM_V1.'/logbook-signoffs', $body)->assertCreated()->assertJsonPath('data.status', 'signed');

    // Other mentors and staff without the assessor role cannot do this.
    $this->postJson(TYM_V1.'/logbook-signoffs', ['enrolment_id' => $this->enrolment, 'module_id' => $this->km->id, 'authentic' => true])->assertStatus(422);
    signInAs($this, 'sipho@tym.test');
    $this->postJson(TYM_V1.'/logbook-signoffs', $body)->assertNotFound();
    $this->postJson(TYM_V1."/logbook-signoffs/{$id}/check", ['outcome' => 'checked'])->assertForbidden();
});

it('puts workplace days on the monthly register, signed by the mentor', function (): void {
    $lastMonth = CarbonImmutable::now('Africa/Johannesburg')->startOfMonth()->subMonth();
    asTenant($this->tym, function () use ($lastMonth) {
        Enrolment::query()->whereKey($this->enrolment)->update(['enrolled_on' => $lastMonth->subMonth()->toDateString()]);
        LearnerPlacement::query()->where('enrolment_id', $this->enrolment)->update(['starts_on' => $lastMonth->subDays(3)->toDateString()]);
    });
    $day = $lastMonth->next('Wednesday');
    signInAs($this, 'ayanda@learner.test');
    $a = $this->postJson(TYM_V1.'/me/logbook', entry($this, null, ['work_date' => $day->toDateString(), 'minutes' => 480]))->assertCreated()->json('data');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, $a['hash'], ['work_date' => $day->addDay()->toDateString(), 'minutes' => 450]))->assertCreated();

    signInOwner($this, $this->tym, 'office2@tym.test');
    $r = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/registers", ['month' => $lastMonth->format('Y-m')])->assertCreated()->json('data');
    $line = collect($r['grid']['learners'])->firstWhere('user_id', $this->ayanda->id);
    expect($line)->toMatchArray(['workplace_days' => 2, 'workplace_hours' => 15.5])->and($r['counts'])->toMatchArray(['mentors' => 1, 'mentors_signed' => 0]);
    expect(strlen($this->get(TYM_V1."/registers/{$r['id']}/pdf")->assertOk()->getContent()))->toBeGreaterThan(1000);

    signInAs($this, 'bheki@mabaso.test');
    $mine = $this->getJson(TYM_V1.'/me/registers')->assertOk()->json('data.0');
    expect($mine['role'])->toBe('mentor');
    $seen = $this->getJson(TYM_V1."/registers/{$r['id']}")->assertOk()->json('data');
    expect($seen['grid']['sessions'])->toBe([])->and($seen['grid']['learners'])->toHaveCount(1)->and($seen['grid']['learners'][0]['marks'])->toBe([]);
    $this->postJson(TYM_V1."/registers/{$r['id']}/sign", ['name' => 'Bheki Mabaso', 'role' => 'mentor'])->assertOk()->assertJsonPath('data.status', 'complete');
});

it('reminds the mentor of weeks left unsigned and nudges learners who stopped logging', function (): void {
    $old = CarbonImmutable::now('Africa/Johannesburg')->startOfWeek()->subWeeks(3);
    signInAs($this, 'ayanda@learner.test');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, null, ['work_date' => $old->toDateString()]))->assertCreated();
    asTenant($this->tym, fn () => LogbookEntry::query()->update(['created_at' => $old->addHours(17)]));

    $this->artisan('logbook:watch')->expectsOutput('Reminded mentors of 1 weeks; nudged 1 learners.')->assertSuccessful();
    $this->artisan('logbook:watch')->expectsOutput('Reminded mentors of 0 weeks; nudged 0 learners.')->assertSuccessful();
    expect(asTenant($this->tym, fn () => LogbookWeek::query()->value('reminded_at')))->not->toBeNull();
    $this->travel(8)->days();
    $this->artisan('logbook:watch')->expectsOutput('Reminded mentors of 1 weeks; nudged 0 learners.')->assertSuccessful();
});
