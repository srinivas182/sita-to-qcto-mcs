<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Notifications\AttendanceAtRisk;
use Modules\Delivery\Services\AttendanceService;
use Modules\Identity\Models\User;

beforeEach(function (): void {
    Notification::fake();
    deliverySetup($this);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $this->ayanda = enrolLearner($this, '082 123 4567', '9202204720083');
    $this->kagiso = enrolLearner($this, '082 222 3333', '9001014800089', 'Kagiso');
    foreach ([[$this->ayanda, 'ayanda'], [$this->kagiso, 'kagiso']] as [$u, $n]) {
        asPlatform(fn () => $u->forceFill(['email' => "{$n}@learner.test", 'password' => 'correct horse battery staple'])->save());
    }
    signInOwner($this, $this->tym, 'reg@tym.test');
    // A Monday early in a month, so the week stays in one month whatever today's date is.
    $mon = CarbonImmutable::parse($this->cohort->intake_on)->addMonth()->startOfMonth()->addDays(7)->startOfWeek();
    // Five classes in the cohort's first full week, taught by Sipho.
    foreach (range(0, 4) as $d) {
        $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/sessions", ['kind' => $d % 2 ? 'practical' : 'knowledge', 'module_id' => $d % 2 ? $this->pm->id : $this->km->id,
            'date' => $mon->addDays($d)->toDateString(), 'start' => '08:00', 'end' => '12:00', 'facilitator_id' => $this->sipho->id])->assertCreated();
    }
    $this->sessions = asTenant($this->tym, fn () => ClassSession::query()->orderBy('starts_at')->get());
    $this->month = $mon->format('Y-m');
    $this->travelTo($mon->addDays(5)->setTime(9, 0));
    $by = asPlatform(fn () => User::query()->where('email', 'sipho@tym.test')->firstOrFail());
    asTenant($this->tym, function () use ($by): void {
        foreach ($this->sessions as $i => $s) {
            app(AttendanceService::class)->mark($s, $this->ayanda->id, $i === 2 ? 'late' : 'present', 'Register', $by);
            app(AttendanceService::class)->mark($s, $this->kagiso->id, $i === 0 ? 'present' : ($i === 1 ? 'excused' : 'absent'), 'Register', $by);
        }
    });
});

it('makes the month\'s register once the month is over, signed by learners and the facilitator, then sent', function (): void {
    signInOwner($this, $this->tym, 'reg2@tym.test');
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/registers", ['month' => $this->month])->assertStatus(422)->assertJsonValidationErrors('month'); // not over yet
    $this->travelTo(CarbonImmutable::parse($this->month.'-01')->addMonth()->addDays(1)->setTime(9, 0));
    signInOwner($this, $this->tym, 'reg3@tym.test');
    $r = $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/registers", ['month' => $this->month])->assertCreated()->json('data');
    $rows = collect($r['grid']['learners'])->keyBy('name');
    expect($r['status'])->toBe('signing')->and($r['grid']['sessions'])->toHaveCount(5)
        ->and($rows['Ayanda Zulu'])->toMatchArray(['held' => 5, 'attended' => 5, 'pct' => 100])
        ->and($rows['Kagiso Zulu'])->toMatchArray(['held' => 5, 'attended' => 1, 'excused' => 1, 'pct' => 25])
        ->and($r['counts'])->toMatchArray(['learners' => 2, 'facilitators' => 1]);

    // A learner sees and signs only their own line; the name must match.
    signInAs($this, 'ayanda@learner.test');
    $mine = $this->getJson(TYM_V1.'/me/registers')->assertOk()->json('data.0');
    expect($mine)->toMatchArray(['my_status' => 'pending', 'role' => 'learner', 'attended' => 5, 'pct' => 100]);
    expect($this->getJson(TYM_V1."/registers/{$r['id']}")->json('data.grid.learners'))->toHaveCount(1);
    $this->postJson(TYM_V1."/registers/{$r['id']}/sign", ['name' => 'Someone Else'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson(TYM_V1."/registers/{$r['id']}/sign", ['name' => ' ayanda  zulu '])->assertOk()->assertJsonPath('data.counts.signed', 1);
    $this->get(TYM_V1."/registers/{$r['id']}/pdf")->assertNotFound(); // the PDF is for staff

    signInAs($this, 'kagiso@learner.test');
    $this->postJson(TYM_V1."/registers/{$r['id']}/dispute", ['reason' => 'I was at the Wednesday class'])->assertOk()->assertJsonPath('data.counts.disputed', 1);

    // Not complete until the facilitator signs; then it can be sent.
    signInOwner($this, $this->tym, 'reg4@tym.test');
    $this->postJson(TYM_V1."/registers/{$r['id']}/submitted", ['sent_on' => now()->toDateString()])->assertStatus(422);
    signInAs($this, 'sipho@tym.test');
    $this->postJson(TYM_V1."/registers/{$r['id']}/sign", ['name' => 'Sipho Ndlovu', 'role' => 'facilitator'])->assertOk()->assertJsonPath('data.status', 'complete');
    $pdf = $this->get(TYM_V1."/registers/{$r['id']}/pdf")->assertOk();
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF')->and($pdf->headers->get('Content-Disposition'))->toContain('attendance-register-'.$this->month);
    signInOwner($this, $this->tym, 'reg5@tym.test');
    $this->postJson(TYM_V1."/registers/{$r['id']}/submitted", ['sent_on' => now()->toDateString(), 'note' => 'Emailed to CETA'])->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/registers", ['month' => $this->month])->assertStatus(422); // sent: not made again
    signInAs($this, 'kagiso@learner.test');
    $this->postJson(TYM_V1."/registers/{$r['id']}/sign", ['name' => 'Kagiso Zulu'])->assertStatus(422);
});

it('makes last month\'s registers by itself early in the month, and reminds unsigned people', function (): void {
    $this->travelTo(CarbonImmutable::parse($this->month.'-01')->addMonth()->addDay()->setTime(6, 20));
    $this->artisan('delivery:registers')->expectsOutputToContain('1 registers made')->assertSuccessful();
    $this->artisan('delivery:registers')->expectsOutputToContain('0 registers made, 0 people reminded')->assertSuccessful();
    $this->travel(4)->days();
    $this->artisan('delivery:registers')->expectsOutputToContain('3 people reminded')->assertSuccessful();
    expect(asTenant($this->tym, fn () => AttendanceRegister::query()->count()))->toBe(1);
});

it('tells learners below the threshold once a week, and the provider', function (): void {
    $this->artisan('delivery:attendance-watch')->expectsOutput('1 learners told')->assertSuccessful();
    $this->artisan('delivery:attendance-watch')->expectsOutput('0 learners told')->assertSuccessful();
    Notification::assertSentOnDemand(AttendanceAtRisk::class, fn ($n) => $n->learners === [['name' => 'Kagiso Zulu', 'pct' => 25]] && $n->threshold === 80);
    $this->travel(8)->days();
    $this->artisan('delivery:attendance-watch')->expectsOutput('1 learners told')->assertSuccessful();
});
