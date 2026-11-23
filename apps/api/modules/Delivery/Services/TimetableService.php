<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Models\Room;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;
use Modules\People\Services\CredentialService;
use Modules\Qualifications\Models\QualificationModule;
use Throwable;

/**
 * The timetable of a cohort: knowledge and practical sessions linked to curriculum
 * modules, in a room or another venue, with a facilitator who must be on the cohort and
 * registered for the qualification on the day. Clashes (facilitator, room, the cohort
 * itself) are refused. Changes in the next two weeks are pushed to learners and the
 * facilitator.
 */
final class TimetableService
{
    public const TZ = 'Africa/Johannesburg';

    public const MAX_SERIES = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CredentialService $credentials,
        private readonly PushService $pushes,
    ) {}

    /**
     * Create one session, or a weekly pattern (repeat.days = ISO weekdays 1–7, until a date).
     *
     * @param  array<string, mixed>  $input
     * @return list<ClassSession>
     */
    public function create(Cohort $cohort, array $input, User $by): array
    {
        $data = $this->validate($input, true);
        $date = CarbonImmutable::parse((string) $data['date'], self::TZ);
        $dates = [$date];
        if (! empty($data['repeat'])) {
            $until = CarbonImmutable::parse((string) $data['repeat']['until'], self::TZ);
            $days = array_map('intval', (array) $data['repeat']['days']);
            $dates = [];
            for ($d = $date; $d->lte($until); $d = $d->addDay()) {
                if (in_array($d->dayOfWeekIso, $days, true)) {
                    $dates[] = $d;
                }
            }
            if ($dates === []) {
                throw ValidationException::withMessages(['repeat' => 'No days fall between those dates.']);
            }
            if (count($dates) > self::MAX_SERIES) {
                throw ValidationException::withMessages(['repeat' => 'At most '.self::MAX_SERIES.' sessions at a time.']);
            }
        }
        $series = count($dates) > 1 ? (string) Str::ulid() : null;

        $created = DB::transaction(function () use ($cohort, $data, $dates, $series, $by): array {
            $out = [];
            foreach ($dates as $d) {
                [$start, $end] = $this->times($d, (string) $data['start'], (string) $data['end']);
                $s = new ClassSession(['cohort_id' => $cohort->id, 'module_id' => $data['module_id'] ?? null, 'kind' => $data['kind'], 'title' => $data['title'] ?? null,
                    'starts_at' => $start, 'ends_at' => $end, 'room_id' => $data['room_id'] ?? null, 'venue' => $data['venue'] ?? null,
                    'facilitator_id' => $data['facilitator_id'] ?? null, 'series_id' => $series, 'notes' => $data['notes'] ?? null, 'created_by' => $by->id]);
                $this->check($cohort, $s);
                $s->save();
                $out[] = $s;
            }

            return $out;
        });
        $this->audit->record('timetable.created', $cohort, [], ['sessions' => count($created), 'series' => $series]);
        $this->tell($created, 'New class on your timetable');

        return $created;
    }

    /**
     * Change one session, or it and the following ones in its series (scope "following").
     * Times are given as clock times and keep each session's own date.
     *
     * @param  array<string, mixed>  $input
     * @return list<ClassSession>
     */
    public function update(ClassSession $session, array $input, string $scope = 'one'): array
    {
        $data = $this->validate($input, false);
        $cohort = Cohort::query()->whereKey($session->cohort_id)->firstOrFail();
        $targets = $scope === 'following' && $session->series_id !== null
            ? ClassSession::query()->where('series_id', $session->series_id)->where('starts_at', '>=', $session->starts_at)->where('status', 'scheduled')->orderBy('starts_at')->get()
            : collect([$session]);

        $changed = DB::transaction(function () use ($targets, $data, $cohort, $scope): array {
            $out = [];
            foreach ($targets as $s) {
                $day = isset($data['date']) && $scope === 'one' ? CarbonImmutable::parse((string) $data['date'], self::TZ) : CarbonImmutable::parse($s->starts_at)->setTimezone(self::TZ);
                $startClock = $data['start'] ?? CarbonImmutable::parse($s->starts_at)->setTimezone(self::TZ)->format('H:i');
                $endClock = $data['end'] ?? CarbonImmutable::parse($s->ends_at)->setTimezone(self::TZ)->format('H:i');
                [$start, $end] = $this->times($day, (string) $startClock, (string) $endClock);
                $s->fill(array_intersect_key($data, array_flip(['module_id', 'kind', 'title', 'room_id', 'venue', 'facilitator_id', 'notes'])));
                $s->forceFill(['starts_at' => $start, 'ends_at' => $end]);
                $this->check($cohort, $s);
                $s->save();
                $out[] = $s;
            }

            return $out;
        });
        $this->audit->record('timetable.changed', $session, [], ['sessions' => count($changed), 'scope' => $scope]);
        $this->tell($changed, 'Class changed');

        return $changed;
    }

    /** @return list<ClassSession> */
    public function cancel(ClassSession $session, string $reason, string $scope = 'one'): array
    {
        $targets = $scope === 'following' && $session->series_id !== null
            ? ClassSession::query()->where('series_id', $session->series_id)->where('starts_at', '>=', $session->starts_at)->where('status', 'scheduled')->get()
            : collect([$session]);
        foreach ($targets as $s) {
            $s->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
        }
        $this->audit->record('timetable.cancelled', $session, [], ['sessions' => $targets->count(), 'reason' => $reason]);
        $this->tell(array_values($targets->all()), 'Class cancelled');

        return array_values($targets->all());
    }

    /**
     * Planned hours per curriculum module against its notional hours (10 per credit).
     *
     * @return list<array{module_id: string, code: string, title: string, kind: string, credits: int, notional_hours: int, planned_hours: float}>
     */
    public function hours(Cohort $cohort): array
    {
        $minutes = ClassSession::query()->where('cohort_id', $cohort->id)->where('status', 'scheduled')->whereNotNull('module_id')->get()
            ->groupBy('module_id')->map(fn (Collection $c) => $c->sum(fn (ClassSession $s) => $s->minutes()));

        return array_values(QualificationModule::query()->where('qualification_id', $cohort->qualification_id)->whereIn('kind', ['KM', 'PM'])->orderBy('sort')->get()
            ->map(fn (QualificationModule $m) => ['module_id' => $m->id, 'code' => $m->code, 'title' => $m->title, 'kind' => $m->kind, 'credits' => $m->credits,
                'notional_hours' => $m->credits * 10, 'planned_hours' => round(((int) $minutes->get($m->id, 0)) / 60, 1)])->all());
    }

    /**
     * @param  Collection<string, QualificationModule>|null  $modules
     * @param  Collection<string, Room>|null  $rooms
     * @param  Collection<string, User>|null  $people
     * @return array<string, mixed>
     */
    public static function present(ClassSession $s, ?Collection $modules = null, ?Collection $rooms = null, ?Collection $people = null): array
    {
        $m = $modules?->get($s->module_id);
        $r = $rooms?->get($s->room_id);
        $f = $people?->get($s->facilitator_id);

        return ['id' => $s->id, 'cohort_id' => $s->cohort_id, 'kind' => $s->kind, 'title' => $s->title ?? $m?->title, 'status' => $s->status, 'cancel_reason' => $s->cancel_reason,
            'starts_at' => CarbonImmutable::parse($s->starts_at)->setTimezone(self::TZ)->toIso8601String(),
            'ends_at' => CarbonImmutable::parse($s->ends_at)->setTimezone(self::TZ)->toIso8601String(),
            'module' => $m ? ['id' => $m->id, 'code' => $m->code, 'title' => $m->title, 'kind' => $m->kind] : null,
            'room' => $r ? ['id' => $r->id, 'name' => $r->name] : null, 'venue' => $s->venue,
            'facilitator' => $f ? ['id' => $f->id, 'name' => $f->fullName()] : null, 'series_id' => $s->series_id, 'notes' => $s->notes];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return Validator::make($input, [
            'kind' => [$req, Rule::in(array_keys(ClassSession::KINDS))],
            'module_id' => ['nullable', 'ulid'],
            'title' => ['nullable', 'string', 'max:150'],
            'date' => [$req, 'date_format:Y-m-d'],
            'start' => [$req, 'date_format:H:i'],
            'end' => [$req, 'date_format:H:i'],
            'room_id' => ['nullable', 'ulid'],
            'venue' => ['nullable', 'string', 'max:150'],
            'facilitator_id' => ['nullable', 'ulid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'repeat' => ['nullable', 'array'],
            'repeat.days' => ['required_with:repeat', 'array', 'min:1'],
            'repeat.days.*' => ['integer', 'between:1,7'],
            'repeat.until' => ['required_with:repeat', 'date_format:Y-m-d', 'after_or_equal:date'],
        ])->validate();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC start and end */
    private function times(CarbonImmutable $day, string $start, string $end): array
    {
        $s = CarbonImmutable::parse($day->format('Y-m-d').' '.$start, self::TZ);
        $e = CarbonImmutable::parse($day->format('Y-m-d').' '.$end, self::TZ);
        if ($e->lte($s)) {
            throw ValidationException::withMessages(['end' => 'The class must end after it starts.']);
        }
        if ($s->diffInMinutes($e) > 12 * 60) {
            throw ValidationException::withMessages(['end' => 'A class can last at most 12 hours.']);
        }

        return [$s->utc(), $e->utc()];
    }

    /** The rules a session must keep; the message says which one and when. */
    private function check(Cohort $cohort, ClassSession $s): void
    {
        $local = CarbonImmutable::parse($s->starts_at)->setTimezone(self::TZ);
        $when = $local->format('D j M H:i');
        if ($local->lt(CarbonImmutable::parse($cohort->intake_on, self::TZ)->startOfDay()) || $local->gt(CarbonImmutable::parse($cohort->planned_end_on, self::TZ)->endOfDay())) {
            throw ValidationException::withMessages(['date' => "{$when} is outside the cohort's dates."]);
        }
        if ($s->module_id !== null) {
            $m = QualificationModule::query()->whereKey($s->module_id)->where('qualification_id', $cohort->qualification_id)->first()
                ?? throw ValidationException::withMessages(['module_id' => 'Choose a module of this cohort\'s qualification.']);
            if ($m->kind === 'WM') {
                throw ValidationException::withMessages(['module_id' => 'Work experience modules happen at the workplace and are recorded in the logbook.']);
            }
        }
        if ($s->room_id !== null && ! Room::query()->whereKey($s->room_id)->where('centre_id', $cohort->centre_id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'Choose a room at this cohort\'s centre.']);
        }
        if ($s->facilitator_id !== null) {
            if (! CohortStaff::query()->where('cohort_id', $cohort->id)->where('user_id', $s->facilitator_id)->where('role', 'facilitator')->exists()) {
                throw ValidationException::withMessages(['facilitator_id' => 'Add this person to the cohort as a facilitator first.']);
            }
            $ok = $this->credentials->eligibility($s->facilitator_id, 'facilitator', $cohort->qualification_id, $local->startOfDay());
            if (! $ok['ok']) {
                throw ValidationException::withMessages(['facilitator_id' => "On {$local->format('j M Y')}: {$ok['reason']}"]);
            }
        }
        $overlap = fn () => ClassSession::query()->where('status', 'scheduled')->when($s->exists, fn ($q) => $q->whereKeyNot($s->id))
            ->where('starts_at', '<', $s->ends_at)->where('ends_at', '>', $s->starts_at);
        if ($overlap()->where('cohort_id', $cohort->id)->exists()) {
            throw ValidationException::withMessages(['start' => "{$when}: the cohort already has a class then."]);
        }
        if ($s->facilitator_id !== null && $overlap()->where('facilitator_id', $s->facilitator_id)->exists()) {
            throw ValidationException::withMessages(['facilitator_id' => "{$when}: the facilitator is teaching another class then."]);
        }
        if ($s->room_id !== null && $overlap()->where('room_id', $s->room_id)->exists()) {
            throw ValidationException::withMessages(['room_id' => "{$when}: the room is booked then."]);
        }
    }

    /** @param  list<ClassSession>  $sessions */
    private function tell(array $sessions, string $title): void
    {
        $soon = array_filter($sessions, fn (ClassSession $s) => CarbonImmutable::parse($s->starts_at)->between(now()->subHour(), now()->addDays(14)));
        if ($soon === []) {
            return;
        }
        $first = reset($soon);
        $userIds = Enrolment::query()->where('cohort_id', $first->cohort_id)->where('status', 'active')->pluck('user_id')
            ->merge(array_filter(array_map(fn (ClassSession $s) => $s->facilitator_id, $soon)))->unique()->all();
        $local = CarbonImmutable::parse($first->starts_at)->setTimezone(self::TZ);
        $body = (count($soon) > 1 ? count($soon).' classes, from ' : '').$local->format('D j M, H:i').($first->status === 'cancelled' && $first->cancel_reason ? ": {$first->cancel_reason}" : '');
        foreach (Device::query()->whereIn('user_id', $userIds)->whereNull('revoked_at')->whereNotNull('push_token')->get() as $device) {
            try {
                $this->pushes->send($device, $title, $body, ['type' => 'timetable', 'session_id' => $first->id]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
