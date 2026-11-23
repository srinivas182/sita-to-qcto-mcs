<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Carbon\CarbonImmutable;
use Modules\Delivery\Models\Attendance;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Services\AttendanceService;
use Modules\Delivery\Support\QrToken;
use Modules\Identity\Models\User;

const SOWETO = ['latitude' => -26.2485, 'longitude' => 27.8540];
const NEAR = ['latitude' => -26.2491, 'longitude' => 27.8546, 'accuracy' => 15]; // about 90 m away
const FAR = ['latitude' => -26.1076, 'longitude' => 28.0567, 'accuracy' => 10];  // Sandton

beforeEach(function (): void {
    deliverySetup($this);
    asTenant($this->tym, fn () => $this->centre->forceFill(SOWETO)->save());
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $this->ayanda = enrolLearner($this, '082 123 4567', '9202204720083');
    $this->kagiso = enrolLearner($this, '082 222 3333', '9001014800089', 'Kagiso');
    foreach ([[$this->ayanda, 'ayanda'], [$this->kagiso, 'kagiso']] as [$u, $n]) {
        asPlatform(fn () => $u->forceFill(['email' => "{$n}@learner.test", 'password' => 'correct horse battery staple'])->save());
    }
    signInOwner($this, $this->tym, 'att@tym.test');
    $this->monday = firstMonday($this);
    $this->session = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['kind' => 'knowledge', 'module_id' => $this->km->id, 'date' => $this->monday,
        'start' => '08:00', 'end' => '12:00', 'room_id' => $this->roomA->id, 'facilitator_id' => $this->sipho->id])->assertCreated()->json('data.0.id');
    $this->at = fn (string $clock) => $this->travelTo(CarbonImmutable::parse("{$this->monday} {$clock}", 'Africa/Johannesburg'));
});

function code(object $test): string
{
    signInAs($test, 'sipho@tym.test');

    return $test->getJson(TYM_V1."/class-sessions/{$test->session}/qr")->assertOk()->json('data.token');
}

it('signs codes that change every 30 seconds and cannot be forged', function (): void {
    $t = QrToken::make($this->tym->id, $this->session, 1_800_000_015);
    expect(QrToken::read($this->tym->id, $t))->toBe(['session_id' => $this->session, 'window' => 60_000_000])
        ->and(QrToken::make($this->tym->id, $this->session, 1_800_000_045))->not->toBe($t)
        ->and(QrToken::read($this->tym->id, substr($t, 0, -1).(str_ends_with($t, '0') ? '1' : '0')))->toBeNull()
        ->and(QrToken::read('01jdifferenttenant00000000', $t))->toBeNull();
});

it('checks a learner in at the centre with a fresh code, late after 10 minutes, and out again', function (): void {
    ($this->at)('07:55');
    $token = code($this);
    signInAs($this, 'ayanda@learner.test');
    $in = $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token] + NEAR)->assertCreated()->json('data');
    expect($in)->toMatchArray(['status' => 'present', 'action' => 'checked_in', 'method' => 'qr', 'location_ok' => true, 'needs_review' => false])
        ->and($in['distance_m'])->toBeLessThan(150)->and($in['session']['title'])->toBe('Building materials');
    $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token] + NEAR)->assertOk()->assertJsonPath('data.action', 'already_checked_in');

    // Too far away, or an old code: refused.
    ($this->at)('08:20');
    $token = code($this);
    signInAs($this, 'kagiso@learner.test');
    $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token] + FAR)->assertStatus(422)->assertJsonValidationErrors('location');
    $this->travel(2)->minutes();
    $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token] + NEAR)->assertStatus(422)->assertJsonValidationErrors('token');
    $fresh = code($this);
    signInAs($this, 'kagiso@learner.test');
    $late = $this->postJson(TYM_V1.'/me/check-ins', ['token' => $fresh] + NEAR)->assertCreated();
    expect($late->json('data.status'))->toBe('late')->and($late->json('data.minutes_late'))->toBe(22);

    // Ayanda leaves at 10:00: checked out, left early.
    ($this->at)('10:00');
    $token = code($this);
    signInAs($this, 'ayanda@learner.test');
    $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token] + NEAR)->assertOk()->assertJsonPath('data.action', 'checked_out')->assertJsonPath('data.status', 'left_early');
});

it('accepts offline check-ins sent later, and sends doubtful ones to the facilitator', function (): void {
    ($this->at)('08:02');
    $token = code($this);
    $scanned = now()->toIso8601String();
    ($this->at)('15:30'); // back online after class
    signInAs($this, 'ayanda@learner.test');
    $a = $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token, 'offline' => true, 'scanned_at' => $scanned] + FAR)->assertCreated()->json('data');
    expect($a)->toMatchArray(['status' => 'present', 'offline' => true, 'location_ok' => false, 'needs_review' => true, 'review_reason' => 'Checked in away from the centre']);

    signInAs($this, 'kagiso@learner.test');
    $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token, 'offline' => true, 'scanned_at' => now()->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors('token'); // claims a later time than the code
    $k = $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token, 'offline' => true, 'scanned_at' => $scanned, 'mocked' => true] + NEAR)->assertCreated()->json('data');
    expect($k['needs_review'])->toBeTrue()->and($k['review_reason'])->toContain('simulated');

    $this->travel(4)->days();
    signInAs($this, 'kagiso@learner.test');
    $this->postJson(TYM_V1.'/me/check-ins', ['token' => $token, 'offline' => true, 'scanned_at' => $scanned] + NEAR)->assertStatus(422)->assertJsonValidationErrors('scanned_at');
});

it('lets only the class facilitator mark, review and see the class list, with reasons kept', function (): void {
    ($this->at)('08:05');
    signInAs($this, 'thabo@tym.test');
    $this->getJson(TYM_V1."/class-sessions/{$this->session}/qr")->assertForbidden();
    signInAs($this, 'sipho@tym.test');
    $this->postJson(TYM_V1."/class-sessions/{$this->session}/attendance", ['user_id' => $this->kagiso->id, 'status' => 'present'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson(TYM_V1."/class-sessions/{$this->session}/attendance", ['user_id' => $this->kagiso->id, 'status' => 'present', 'reason' => 'No phone'])
        ->assertOk()->assertJsonPath('data.method', 'facilitator');
    $list = $this->getJson(TYM_V1."/class-sessions/{$this->session}/attendance")->assertOk()->json('data');
    expect($list['counts'])->toMatchArray(['expected' => 2, 'in' => 1])->and(collect($list['learners'])->pluck('name')->all())->toBe(['Ayanda Zulu', 'Kagiso Zulu']);

    // Ayanda sends a sick note; the facilitator accepts it: excused.
    signInAs($this, 'ayanda@learner.test');
    $note = $this->postJson(TYM_V1."/me/class-sessions/{$this->session}/note", ['reason' => 'Clinic visit'])->assertCreated()->json('data');
    expect($note)->toMatchArray(['status' => 'absent', 'needs_review' => true]);
    signInAs($this, 'sipho@tym.test');
    $this->postJson(TYM_V1."/attendances/{$note['id']}/review", ['accept' => true])->assertOk()->assertJsonPath('data.status', 'excused');
    expect(asTenant($this->tym, fn () => Attendance::query()->where('user_id', $this->ayanda->id)->value('reason')))->toBe('Absence note accepted');
});

it('reports attendance per learner, leaves excused classes out, and flags those below the threshold', function (): void {
    signInOwner($this, $this->tym, 'att2@tym.test');
    foreach (['2', '3', '4'] as $n) { // three more classes that week
        $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['kind' => 'practical', 'module_id' => $this->pm->id,
            'date' => CarbonImmutable::parse($this->monday)->addDays((int) $n - 1)->toDateString(), 'start' => '08:00', 'end' => '12:00', 'facilitator_id' => $this->sipho->id])->assertCreated();
    }
    $sessions = asTenant($this->tym, fn () => ClassSession::query()->orderBy('starts_at')->get());
    ($this->at)('08:05');
    $this->travel(3)->days();
    $by = asPlatform(fn () => User::query()->where('email', 'sipho@tym.test')->firstOrFail());
    asTenant($this->tym, function () use ($sessions, $by): void {
        $svc = app(AttendanceService::class);
        foreach ($sessions as $i => $s) {
            $svc->mark($s, $this->ayanda->id, 'present', 'Register', $by);
            $svc->mark($s, $this->kagiso->id, $i === 0 ? 'excused' : ($i === 1 ? 'present' : 'absent'), 'Register', $by);
        }
    });
    $r = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/attendance")->assertOk()->json('data');
    $rows = collect($r['learners'])->keyBy('name');
    expect($r['sessions'])->toBe(4)
        ->and($rows['Ayanda Zulu'])->toMatchArray(['held' => 4, 'attended' => 4, 'pct' => 100, 'at_risk' => false])
        ->and($rows['Kagiso Zulu'])->toMatchArray(['held' => 4, 'attended' => 1, 'excused' => 1, 'absent' => 2, 'pct' => 33, 'at_risk' => true]);
});
