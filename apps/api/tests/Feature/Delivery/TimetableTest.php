<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Delivery\Models\ClassSession;
use Modules\Identity\Models\Device;
use Modules\People\Models\StaffCredential;

beforeEach(function (): void {
    deliverySetup($this);
    signInOwner($this, $this->tym, 'timetable@tym.test');
});

it('builds a weekly timetable linked to curriculum modules, in local time', function (): void {
    $mon = firstMonday($this);
    $made = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", [
        'kind' => 'knowledge', 'module_id' => $this->km->id, 'date' => $mon, 'start' => '08:00', 'end' => '12:00', 'room_id' => $this->roomA->id,
        'facilitator_id' => $this->sipho->id, 'repeat' => ['days' => [1, 3], 'until' => CarbonImmutable::parse($mon)->addDays(13)->toDateString()],
    ])->assertCreated()->json('data');
    expect($made)->toHaveCount(4)->and($made[0]['starts_at'])->toBe("{$mon}T08:00:00+02:00")->and($made[0]['module']['code'])->toBe('KM-01')
        ->and($made[0]['room']['name'])->toBe('Room 1')->and($made[0]['facilitator']['name'])->toBe('Sipho Ndlovu')->and($made[0]['series_id'])->not->toBeNull();
    expect(asTenant($this->tym, fn () => ClassSession::query()->first()->starts_at->format('H:i')))->toBe('06:00'); // stored in UTC

    $t = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions")->assertOk()->json();
    $km = collect($t['hours'])->firstWhere('code', 'KM-01');
    expect($km)->toMatchArray(['notional_hours' => 40, 'planned_hours' => 16])->and(collect($t['hours'])->pluck('code')->all())->toBe(['KM-01', 'PM-01'])
        ->and($t['options']['facilitators'])->toHaveCount(2)->and($t['options']['rooms'])->toHaveCount(2);
});

it('refuses clashes, outside dates, workplace modules and unregistered facilitators', function (): void {
    $mon = firstMonday($this);
    $base = ['kind' => 'practical', 'module_id' => $this->pm->id, 'date' => $mon, 'start' => '09:00', 'end' => '11:00'];
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", $base + ['room_id' => $this->roomB->id, 'facilitator_id' => $this->sipho->id])->assertCreated();

    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['start' => '10:00', 'end' => '12:00'] + $base)->assertStatus(422)->assertJsonValidationErrors('start'); // the cohort is busy
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['date' => '2020-01-06'] + $base)->assertStatus(422)->assertJsonValidationErrors('date');
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['module_id' => $this->wm->id, 'start' => '13:00', 'end' => '14:00'] + $base)->assertStatus(422)->assertJsonValidationErrors('module_id');
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['start' => '12:00', 'end' => '11:00'] + $base)->assertStatus(422)->assertJsonValidationErrors('end');

    // Another cohort at the same centre: the room and the facilitator are taken.
    $other = asTenant($this->tym, fn () => tap($this->cohort->replicate(['qcto_alerted_for']), fn ($c) => $c->forceFill(['name' => 'Bricklayer B'])->save()));
    asTenant($this->tym, fn () => CohortStaff::query()->create(['cohort_id' => $other->id, 'user_id' => $this->sipho->id, 'role' => 'facilitator']));
    $this->postJson(TYM_V1."/cohorts/{$other->id}/sessions", $base + ['room_id' => $this->roomB->id])->assertStatus(422)->assertJsonValidationErrors('room_id');
    $this->postJson(TYM_V1."/cohorts/{$other->id}/sessions", $base + ['facilitator_id' => $this->sipho->id])->assertStatus(422)->assertJsonValidationErrors('facilitator_id');
    $this->postJson(TYM_V1."/cohorts/{$other->id}/sessions", $base + ['facilitator_id' => $this->thabo->id])->assertStatus(422)->assertJsonValidationErrors('facilitator_id'); // not on that cohort

    // A facilitator whose registration has lapsed by then.
    asTenant($this->tym, fn () => StaffCredential::query()->where('user_id', $this->thabo->id)->update(['expires_on' => now()->addDays(1)]));
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['start' => '13:00', 'end' => '15:00', 'facilitator_id' => $this->thabo->id] + $base)
        ->assertStatus(422)->assertJsonValidationErrors('facilitator_id');
});

it('changes or cancels one session or the rest of a series, and tells learners about the next two weeks', function (): void {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $learner = enrolLearner($this, '082 123 4567', '9202204720083');
    asTenant($this->tym, fn () => Device::query()->create(['user_id' => $learner->id, 'name' => 'Phone', 'platform' => 'android', 'push_token' => 'ExponentPushToken[abc]', 'public_key' => 'k', 'offline_key_fingerprint' => 'f', 'tenant_id' => $this->tym->id]));
    $this->travelTo(CarbonImmutable::parse($this->cohort->intake_on)->subDays(2));
    signInOwner($this, $this->tym, 'timetable2@tym.test');
    $mon = firstMonday($this);
    $made = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['kind' => 'knowledge', 'module_id' => $this->km->id, 'date' => $mon, 'start' => '08:00', 'end' => '10:00',
        'repeat' => ['days' => [1], 'until' => CarbonImmutable::parse($mon)->addWeeks(5)->toDateString()]])->assertCreated()->json('data');
    expect($made)->toHaveCount(6);
    Http::assertSent(fn ($r) => str_contains($r->body(), 'New class on your timetable'));

    $moved = $this->patchJson(TYM_V1."/class-sessions/{$made[2]['id']}", ['scope' => 'following', 'start' => '13:00', 'end' => '15:00'])->assertOk()->json('data');
    expect($moved)->toHaveCount(4)->and(substr($moved[0]['starts_at'], 11, 5))->toBe('13:00');
    $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions")->assertJsonPath('data.0.starts_at', "{$mon}T08:00:00+02:00");

    $this->postJson(TYM_V1."/class-sessions/{$made[1]['id']}/cancel", ['reason' => 'Public holiday'])->assertOk()->assertJsonPath('data.0.status', 'cancelled');
    Http::assertSent(fn ($r) => str_contains($r->body(), 'Class cancelled') && str_contains($r->body(), 'Public holiday'));

    // The learner sees their classes; cancelled ones say why.
    asPlatform(fn () => $learner->forceFill(['email' => 'ayanda@learner.test', 'password' => 'correct horse battery staple'])->save());
    signInAs($this, 'ayanda@learner.test');
    $mine = $this->getJson(TYM_V1.'/me/timetable?from='.$mon.'&to='.CarbonImmutable::parse($mon)->addDays(13)->toDateString())->assertOk()->json('data');
    expect($mine)->toHaveCount(2)->and($mine[1]['status'])->toBe('cancelled')->and($mine[0]['cohort'])->toBe('Bricklayer February 2027')->and($mine[0]['teaching'])->toBeFalse();
});

it('manages rooms per centre', function (): void {
    $this->postJson(TYM_V1."/centres/{$this->centre->id}/rooms", ['name' => 'Room 1'])->assertStatus(422)->assertJsonValidationErrors('name');
    $id = $this->postJson(TYM_V1."/centres/{$this->centre->id}/rooms", ['name' => 'Computer lab', 'kind' => 'computer_lab', 'capacity' => 20])->assertCreated()->json('data.id');
    $this->patchJson(TYM_V1."/centres/{$this->centre->id}/rooms/{$id}", ['active' => false])->assertOk();
    expect($this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions")->json('options.rooms'))->toHaveCount(2);
});
