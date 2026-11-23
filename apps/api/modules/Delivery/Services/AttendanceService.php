<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\Attendance;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Models\DeliverySettings;
use Modules\Delivery\Support\QrToken;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;

/**
 * Attendance: learners scan the facilitator's changing QR code at the centre (also
 * offline, sent later), facilitators mark those without a phone, and doubtful check-ins
 * wait for the facilitator's review. Only the distance from the centre is kept.
 */
final class AttendanceService
{
    /** A code is accepted for this long after its window starts (two windows). */
    public const CODE_LIFETIME = 60;

    /** Check-in opens this long before the class starts. */
    public const OPENS_BEFORE = 30;

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** @return array{token: string, refresh_in: int} */
    public function qr(ClassSession $session): array
    {
        $now = now()->getTimestamp();
        $token = QrToken::make((string) $this->context->tenantId(), $session->id, $now);

        return ['token' => $token, 'refresh_in' => QrToken::WINDOW_SECONDS - ($now % QrToken::WINDOW_SECONDS)];
    }

    /**
     * A learner scans the code. Online the code must be fresh and the phone at the centre;
     * offline the scan time must match the code, and doubtful scans go to the facilitator.
     *
     * @param  array<string, mixed>  $input
     * @return array{attendance: Attendance, action: string}
     */
    public function checkIn(User $learner, array $input, ?string $deviceId): array
    {
        $data = Validator::make($input, [
            'token' => ['required', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'mocked' => ['sometimes', 'boolean'],
            'offline' => ['sometimes', 'boolean'],
            'scanned_at' => ['required_if:offline,true', 'nullable', 'date'],
        ])->validate();
        $settings = DeliverySettings::current();
        $code = QrToken::read((string) $this->context->tenantId(), (string) $data['token'])
            ?? throw ValidationException::withMessages(['token' => 'This is not a check-in code from your training provider.']);
        $offline = (bool) ($data['offline'] ?? false);
        $now = CarbonImmutable::now();
        $at = $offline ? CarbonImmutable::parse((string) $data['scanned_at']) : $now;
        if ($offline) {
            if ($at->gt($now->addMinutes(2))) {
                throw ValidationException::withMessages(['scanned_at' => 'The phone\'s clock is ahead. Check the date and time on the phone.']);
            }
            if ($at->lt($now->subHours($settings->offline_sync_hours))) {
                throw ValidationException::withMessages(['scanned_at' => "Check-ins must reach us within {$settings->offline_sync_hours} hours. Ask your facilitator to mark you."]);
            }
        }
        $age = QrToken::age($code['window'], $at->getTimestamp());
        if ($age < -5 || $age > self::CODE_LIFETIME) {
            throw ValidationException::withMessages(['token' => 'This code has expired. Scan the code on the screen now.']);
        }
        $session = ClassSession::query()->whereKey($code['session_id'])->first()
            ?? throw ValidationException::withMessages(['token' => 'This class is not on your timetable.']);
        if ($session->status !== 'scheduled') {
            throw ValidationException::withMessages(['token' => 'This class was cancelled.']);
        }
        if (! $this->expected($session, $learner->id)) {
            throw ValidationException::withMessages(['token' => 'You are not enrolled in this class.']);
        }
        $starts = CarbonImmutable::parse($session->starts_at);
        $ends = CarbonImmutable::parse($session->ends_at);
        if ($at->lt($starts->subMinutes(self::OPENS_BEFORE)) || $at->gt($ends)) {
            throw ValidationException::withMessages(['token' => 'Check-in is open from 30 minutes before the class until it ends.']);
        }

        [$distance, $locationOk, $review] = $this->place($session, $data, $settings);
        if ($locationOk === false && ! $offline) {
            throw ValidationException::withMessages(['location' => "You seem to be about {$this->km($distance)} from the centre. Check in when you are there, or ask your facilitator."]);
        }

        $existing = Attendance::query()->where('session_id', $session->id)->where('user_id', $learner->id)->first();
        if ($existing !== null && $existing->method === 'facilitator') {
            return ['attendance' => $existing, 'action' => 'already_marked'];
        }
        if ($existing !== null && $existing->checked_in_at !== null) {
            // A second scan at least 30 minutes later checks the learner out.
            if (CarbonImmutable::parse($existing->checked_in_at)->diffInMinutes($at) < 30 || $existing->checked_out_at !== null) {
                return ['attendance' => $existing, 'action' => 'already_checked_in'];
            }
            $early = $at->lt($ends->subMinutes(15));
            $existing->forceFill(['checked_out_at' => $at, 'status' => $early ? 'left_early' : $existing->status])->save();

            return ['attendance' => $existing, 'action' => 'checked_out'];
        }

        $late = max(0, (int) floor($starts->diffInMinutes($at, false)));
        $a = $existing ?? new Attendance(['session_id' => $session->id, 'user_id' => $learner->id]);
        $a->fill(['status' => $late > $settings->late_after_minutes ? 'late' : 'present', 'checked_in_at' => $at, 'minutes_late' => $late > $settings->late_after_minutes ? $late : 0,
            'method' => 'qr', 'offline' => $offline, 'distance_m' => $distance, 'location_ok' => $locationOk, 'needs_review' => $review !== null, 'review_reason' => $review,
            'device_id' => $deviceId])->save();

        return ['attendance' => $a, 'action' => 'checked_in'];
    }

    /** The facilitator marks a learner (no phone, wrong code, excused absence); a reason is always kept. */
    public function mark(ClassSession $session, string $userId, string $status, string $reason, User $by): Attendance
    {
        if (! array_key_exists($status, Attendance::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Choose a status.']);
        }
        if (! $this->expected($session, $userId)) {
            throw ValidationException::withMessages(['user_id' => 'This learner is not enrolled in this class.']);
        }
        if (CarbonImmutable::parse($session->starts_at)->subMinutes(self::OPENS_BEFORE)->isFuture()) {
            throw ValidationException::withMessages(['status' => 'Attendance can be marked from 30 minutes before the class.']);
        }
        $a = Attendance::query()->firstOrNew(['session_id' => $session->id, 'user_id' => $userId]);
        $before = $a->exists ? $a->status : null;
        $a->fill(['status' => $status, 'method' => $a->exists && $a->method === 'qr' && in_array($status, Attendance::ATTENDED, true) ? 'qr' : 'facilitator',
            'marked_by' => $by->id, 'reason' => $reason, 'needs_review' => false,
            'checked_in_at' => $a->checked_in_at ?? (in_array($status, Attendance::ATTENDED, true) ? now() : null)])->save();
        $this->audit->record('attendance.marked', $a, ['status' => $before], ['status' => $status, 'reason' => $reason]);

        return $a;
    }

    /** A learner tells the provider why they will miss, or missed, a class (a sick note, say). */
    public function note(ClassSession $session, User $learner, string $reason, ?string $fileId): Attendance
    {
        if (! $this->expected($session, $learner->id)) {
            throw ValidationException::withMessages(['session_id' => 'This class is not on your timetable.']);
        }
        if ($fileId !== null && ! File::query()->whereKey($fileId)->where('owner_id', $learner->id)->where('purpose', 'attendance_note')->exists()) {
            throw ValidationException::withMessages(['file_id' => 'Upload the note again.']);
        }
        $a = Attendance::query()->firstOrNew(['session_id' => $session->id, 'user_id' => $learner->id]);
        if ($a->exists && in_array($a->status, Attendance::ATTENDED, true)) {
            throw ValidationException::withMessages(['session_id' => 'You were at this class.']);
        }
        $a->fill(['status' => $a->status ?? 'absent', 'method' => $a->method ?? 'note', 'reason' => $reason, 'note_file_id' => $fileId ?? $a->note_file_id,
            'needs_review' => true, 'review_reason' => 'Absence note'])->save();

        return $a;
    }

    /**
     * The class list: every learner expected (active enrolment, enrolled by the day), with
     * their attendance if any.
     *
     * @return list<array<string, mixed>>
     */
    public function roster(ClassSession $session): array
    {
        $day = CarbonImmutable::parse($session->starts_at)->setTimezone(TimetableService::TZ)->toDateString();
        $enrolled = Enrolment::query()->where('cohort_id', $session->cohort_id)->whereIn('status', ['active', 'suspended', 'completed'])
            ->whereDate('enrolled_on', '<=', $day)->pluck('user_id');
        $people = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $enrolled)->orderBy('first_name')->get());
        $marks = Attendance::query()->where('session_id', $session->id)->get()->keyBy('user_id');

        return array_values($people->map(fn (User $u) => ['user_id' => $u->id, 'name' => $u->fullName()]
            + ['attendance' => ($a = $marks->get($u->id)) ? self::present($a) : null])->all());
    }

    /**
     * Attendance per learner (and per module) for a cohort over a period. Classes count
     * once they have started; excused absences are left out of the percentage.
     *
     * @return array{learners: list<array<string, mixed>>, sessions: int}
     */
    public function report(Cohort $cohort, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sessions = ClassSession::query()->where('cohort_id', $cohort->id)->where('status', 'scheduled')
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])->where('starts_at', '<=', now())->get();
        $enrolments = Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $marks = Attendance::query()->whereIn('session_id', $sessions->pluck('id'))->get()->groupBy('user_id');
        $people = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id'));
        $threshold = DeliverySettings::current()->threshold_pct;

        $rows = [];
        foreach ($enrolments as $e) {
            $held = $sessions->filter(fn (ClassSession $s) => CarbonImmutable::parse($s->starts_at)->setTimezone(TimetableService::TZ)->toDateString() >= $e->enrolled_on->toDateString());
            $mine = ($marks->get($e->user_id) ?? collect())->keyBy('session_id');
            $attended = $held->filter(fn (ClassSession $s) => in_array($mine->get($s->id)?->status, Attendance::ATTENDED, true))->count();
            $excused = $held->filter(fn (ClassSession $s) => $mine->get($s->id)?->status === 'excused')->count();
            $counted = $held->count() - $excused;
            $pct = $counted > 0 ? (int) round($attended * 100 / $counted) : null;
            $rows[] = ['user_id' => $e->user_id, 'name' => $people->get($e->user_id)?->fullName(), 'held' => $held->count(), 'attended' => $attended, 'excused' => $excused,
                'absent' => $held->count() - $attended - $excused, 'late' => $mine->where('status', 'late')->count(), 'pct' => $pct,
                'at_risk' => $pct !== null && $pct < $threshold, 'to_review' => $mine->where('needs_review', true)->count()];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return ['learners' => $rows, 'sessions' => $sessions->count()];
    }

    /** @return array<string, mixed> */
    public static function present(Attendance $a): array
    {
        $tz = TimetableService::TZ;

        return ['id' => $a->id, 'status' => $a->status, 'label' => Attendance::STATUSES[$a->status] ?? $a->status, 'method' => $a->method, 'offline' => $a->offline,
            'checked_in_at' => $a->checked_in_at ? CarbonImmutable::parse($a->checked_in_at)->setTimezone($tz)->toIso8601String() : null,
            'checked_out_at' => $a->checked_out_at ? CarbonImmutable::parse($a->checked_out_at)->setTimezone($tz)->toIso8601String() : null,
            'minutes_late' => $a->minutes_late, 'distance_m' => $a->distance_m, 'location_ok' => $a->location_ok, 'needs_review' => $a->needs_review,
            'review_reason' => $a->review_reason, 'reason' => $a->reason, 'note_file_id' => $a->note_file_id];
    }

    private function expected(ClassSession $session, string $userId): bool
    {
        $day = CarbonImmutable::parse($session->starts_at)->setTimezone(TimetableService::TZ)->toDateString();

        return Enrolment::query()->where('cohort_id', $session->cohort_id)->where('user_id', $userId)->where('status', 'active')->whereDate('enrolled_on', '<=', $day)->exists();
    }

    /**
     * Distance from the centre and whether it is close enough, allowing for the phone's
     * stated accuracy (up to 100 m). Returns a reason when the facilitator should look.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: bool|null, 2: string|null}
     */
    private function place(ClassSession $session, array $data, DeliverySettings $settings): array
    {
        $centre = Centre::query()->whereKey(Cohort::query()->whereKey($session->cohort_id)->value('centre_id'))->first();
        if (! empty($data['mocked'])) {
            return [null, null, 'The phone reported a simulated location'];
        }
        if ($centre === null || $centre->latitude === null || $centre->longitude === null) {
            return [null, null, null]; // the centre has no location set: nothing to compare
        }
        if (! isset($data['latitude'], $data['longitude'])) {
            return [null, null, 'Location was not available'];
        }
        $d = (int) round(self::metres((float) $centre->latitude, (float) $centre->longitude, (float) $data['latitude'], (float) $data['longitude']));
        $slack = min(100.0, (float) ($data['accuracy'] ?? 0));
        $ok = $d - $slack <= $settings->radius_m;

        return [$d, $ok, $ok ? null : 'Checked in away from the centre'];
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private function km(?int $m): string
    {
        return $m === null ? 'far' : ($m >= 1000 ? round($m / 1000, 1).' km' : "{$m} m");
    }
}
