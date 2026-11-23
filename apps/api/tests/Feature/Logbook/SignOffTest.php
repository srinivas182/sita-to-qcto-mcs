<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Carbon\CarbonImmutable;
use Modules\Employers\Models\Mentor;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Models\LogbookWeek;

beforeEach(function (): void {
    logbookSetup($this);
    placed($this);
    $this->bheki = makeMember($this->tym, ['host-employer'], ['email' => 'bheki@mabaso.test', 'first_name' => 'Bheki', 'last_name' => 'Mabaso', 'password' => 'correct horse battery staple']);
    asTenant($this->tym, fn () => Mentor::query()->whereKey($this->mentor->id)->update(['user_id' => $this->bheki->id]));
    $this->monday = CarbonImmutable::now('Africa/Johannesburg')->startOfWeek()->subWeek();
    signInAs($this, 'ayanda@learner.test');
    $this->a = $this->postJson(TYM_V1.'/me/logbook', entry($this, null, ['work_date' => $this->monday->toDateString()]))->assertCreated()->json('data');
    $this->b = $this->postJson(TYM_V1.'/me/logbook', entry($this, $this->a['hash'], ['work_date' => $this->monday->addDay()->toDateString(), 'minutes' => 300]))->assertCreated()->json('data');
});

it('lists the week for the learner\'s mentor only, who signs it with a confirmation', function (): void {
    signInAs($this, 'bheki@mabaso.test');
    $data = $this->getJson(TYM_V1.'/mentoring/weeks')->assertOk()->json('data');
    expect($data['is_mentor'])->toBeTrue()->and($data['learners'][0]['name'])->toBe($this->ayanda->fullName())
        ->and($data['weeks'][0])->toMatchArray(['week_start' => $this->monday->toDateString(), 'status' => 'open', 'entries' => 2, 'minutes' => 540]);
    $week = $data['weeks'][0]['id'];
    $this->getJson(TYM_V1."/logbook-weeks/{$week}")->assertOk()->assertJsonCount(2, 'data.entries')->assertJsonPath('data.entries.0.activity', 'Mix mortar');
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/sign")->assertStatus(422)->assertJsonValidationErrors('confirm');
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/sign", ['confirm' => true])->assertOk()->assertJsonPath('data.status', 'signed');
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/sign", ['confirm' => true])->assertStatus(422)->assertJsonValidationErrors('week');

    // Signed entries are locked: no new entries for that week, no corrections.
    signInAs($this, 'ayanda@learner.test');
    $this->postJson(TYM_V1.'/me/logbook', entry($this, $this->b['hash'], ['work_date' => $this->monday->addDays(2)->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('work_date');
    $this->postJson(TYM_V1."/me/logbook/{$this->a['id']}/correct", entry($this, $this->b['hash']))->assertStatus(422)->assertJsonValidationErrors('entry');
    $mine = $this->getJson(TYM_V1.'/me/logbook')->assertOk()->json('data');
    expect($mine['weeks'][0]['status'])->toBe('signed')->and($mine['hours'][0]['signed_hours'])->toEqual(9);

    // Someone else, even another mentor at the employer, cannot see or sign it.
    $other = makeMember($this->tym, ['host-employer'], ['email' => 'zodwa@mabaso.test', 'password' => 'correct horse battery staple']);
    asTenant($this->tym, fn () => Mentor::query()->create(['employer_id' => $this->mentor->employer_id, 'name' => 'Zodwa Khumalo', 'designation' => 'Supervisor',
        'years_experience' => 9, 'site_ids' => [$this->site->id], 'user_id' => $other->id]));
    signInAs($this, 'zodwa@mabaso.test');
    $this->getJson(TYM_V1.'/mentoring/weeks')->assertOk()->assertJsonCount(0, 'data.weeks');
    $this->getJson(TYM_V1."/logbook-weeks/{$week}")->assertNotFound();
});

it('sends entries back with a comment; the learner\'s correction reopens the week for signing', function (): void {
    signInAs($this, 'bheki@mabaso.test');
    $week = $this->getJson(TYM_V1.'/mentoring/weeks')->json('data.weeks.0.id');
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/return", ['comment' => 'ok'])->assertStatus(422)->assertJsonValidationErrors('comment');
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/return", ['comment' => 'You left at 13:00 on Tuesday, not 17:00.', 'entry_ids' => [$this->b['id']]])
        ->assertOk()->assertJsonPath('data.status', 'returned');
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/sign", ['confirm' => true])->assertStatus(422)->assertJsonValidationErrors('week');

    signInAs($this, 'ayanda@learner.test');
    $mine = $this->getJson(TYM_V1.'/me/logbook')->json('data');
    $returned = collect($mine['entries'])->firstWhere('id', $this->b['id']);
    expect($returned)->toMatchArray(['status' => 'returned', 'comment' => 'You left at 13:00 on Tuesday, not 17:00.'])
        ->and(collect($mine['entries'])->firstWhere('id', $this->a['id'])['status'])->toBe('submitted');
    $this->postJson(TYM_V1."/me/logbook/{$this->b['id']}/correct", entry($this, $this->b['hash'], ['work_date' => $this->monday->addDay()->toDateString(), 'minutes' => 180]))->assertCreated();
    expect(asTenant($this->tym, fn () => LogbookWeek::query()->whereKey($week)->value('status')))->toBe('open');

    signInAs($this, 'bheki@mabaso.test');
    $this->getJson(TYM_V1."/logbook-weeks/{$week}")->assertJsonCount(2, 'data.entries'); // the corrected one is left out
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/sign", ['confirm' => true])->assertOk();
});

it('shows the provider unsigned weeks and a signature that no longer matches', function (): void {
    signInOwner($this, $this->tym, 'office2@tym.test');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/logbook")->assertOk()->assertJsonPath('data.0.unsigned_weeks', 1)->assertJsonPath('data.0.chain.signatures_ok', true);

    signInAs($this, 'bheki@mabaso.test');
    $week = $this->getJson(TYM_V1.'/mentoring/weeks')->json('data.weeks.0.id');
    $this->postJson(TYM_V1."/logbook-weeks/{$week}/sign", ['confirm' => true])->assertOk();
    // An entry changed behind the system's back after signing.
    asTenant($this->tym, fn () => LogbookEntry::query()->whereKey($this->a['id'])->update(['hash' => str_repeat('0', 64)]));

    signInOwner($this, $this->tym, 'office3@tym.test');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/logbook")->assertOk()->assertJsonPath('data.0.unsigned_weeks', 0)
        ->assertJsonPath('data.0.chain.signatures_ok', false)->assertJsonPath('data.0.chain.ok', false);
});
