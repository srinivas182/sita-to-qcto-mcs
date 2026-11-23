<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\Attendance;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Models\DeliverySettings;
use Modules\Delivery\Services\AttendanceService;
use Modules\Delivery\Services\TimetableService;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\QualificationModule;

/** The check-in code, the class list and marking, reviews, reports and settings; learners' check-ins and notes. */
final class AttendanceController
{
    public function qr(Request $request, string $session): JsonResponse
    {
        $s = $this->deliverable($request, $session);

        return response()->json(['data' => $this->attendance()->qr($s)]);
    }

    public function roster(Request $request, string $session): JsonResponse
    {
        $s = $this->deliverable($request, $session);
        $roster = $this->attendance()->roster($s);
        $count = fn (array $statuses) => count(array_filter($roster, fn ($r) => in_array($r['attendance']['status'] ?? null, $statuses, true)));
        $module = $s->module_id ? QualificationModule::query()->whereKey($s->module_id)->first() : null;

        return response()->json(['data' => [
            'session' => TimetableService::present($s, $module ? collect([$module->id => $module]) : null) + ['cohort' => Cohort::query()->whereKey($s->cohort_id)->value('name')],
            'learners' => $roster,
            'counts' => ['expected' => count($roster), 'in' => $count(Attendance::ATTENDED), 'excused' => $count(['excused']),
                'to_review' => count(array_filter($roster, fn ($r) => (bool) ($r['attendance']['needs_review'] ?? false)))],
        ]]);
    }

    public function mark(Request $request, string $session): JsonResponse
    {
        $s = $this->deliverable($request, $session);
        $data = $request->validate([
            'user_id' => ['required', 'ulid'],
            'status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ], ['reason.required' => 'Say why, for the record (for example "No phone", "Sick note seen").']);
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => AttendanceService::present($this->attendance()->mark($s, $data['user_id'], $data['status'], $data['reason'], $by))]);
    }

    /** Accept a doubtful check-in or an absence note, or turn it down (marked absent). */
    public function review(Request $request, string $attendance): JsonResponse
    {
        $a = Attendance::query()->whereKey($attendance)->firstOrFail();
        $s = $this->deliverable($request, $a->session_id);
        $data = $request->validate(['accept' => ['required', 'boolean'], 'reason' => ['required_if:accept,false', 'nullable', 'string', 'max:300']]);
        /** @var User $by */
        $by = $request->user();
        $note = $a->review_reason === 'Absence note';
        if (! $data['accept']) {
            $a = $this->attendance()->mark($s, $a->user_id, 'absent', (string) $data['reason'], $by);
        } elseif ($note) {
            $a = $this->attendance()->mark($s, $a->user_id, 'excused', 'Absence note accepted', $by);
        } else {
            $a->forceFill(['needs_review' => false, 'marked_by' => $by->id])->save();
        }

        return response()->json(['data' => AttendanceService::present($a)]);
    }

    public function report(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = Cohort::query()->whereKey($cohort)->firstOrFail();
        $this->centre($request, $c->centre_id, 'cohorts.view');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = CarbonImmutable::parse((string) ($data['from'] ?? $c->intake_on->toDateString()), TimetableService::TZ)->startOfDay();
        $to = CarbonImmutable::parse((string) ($data['to'] ?? now()->toDateString()), TimetableService::TZ)->endOfDay();

        return response()->json(['data' => $this->attendance()->report($c, $from, $to) + ['from' => $from->toDateString(), 'to' => $to->toDateString(),
            'threshold_pct' => DeliverySettings::current()->threshold_pct]]);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('cohorts.view');

        return response()->json(['data' => DeliverySettings::current()->only(['radius_m', 'late_after_minutes', 'threshold_pct', 'offline_sync_hours'])]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $data = $request->validate([
            'radius_m' => ['sometimes', 'integer', 'between:50,2000'],
            'late_after_minutes' => ['sometimes', 'integer', 'between:0,120'],
            'threshold_pct' => ['sometimes', 'integer', 'between:50,100'],
            'offline_sync_hours' => ['sometimes', 'integer', 'between:12,168'],
        ]);
        $s = DeliverySettings::current();
        $s->fill($data)->save();

        return response()->json(['data' => $s->only(['radius_m', 'late_after_minutes', 'threshold_pct', 'offline_sync_hours'])]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        // A phone's token names its device; a web session has a transient token with none.
        $device = data_get($me->currentAccessToken(), 'device_id');
        $r = $this->attendance()->checkIn($me, $request->all(), is_string($device) ? $device : null);
        $s = ClassSession::query()->whereKey($r['attendance']->session_id)->firstOrFail();

        return response()->json(['data' => AttendanceService::present($r['attendance']) + ['action' => $r['action'],
            'session' => ['title' => $s->title ?? QualificationModule::query()->whereKey($s->module_id)->value('title'), 'starts_at' => CarbonImmutable::parse($s->starts_at)->setTimezone(TimetableService::TZ)->toIso8601String()]]],
            $r['action'] === 'checked_in' ? 201 : 200);
    }

    public function note(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300'], 'file_id' => ['nullable', 'ulid']]);
        $s = ClassSession::query()->whereKey($session)->firstOrFail();
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => AttendanceService::present($this->attendance()->note($s, $me, $data['reason'], $data['file_id'] ?? null))], 201);
    }

    /** The facilitator of the class, or someone who manages cohorts at its centre. */
    private function deliverable(Request $request, string $id): ClassSession
    {
        $s = ClassSession::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centre = (string) Cohort::query()->whereKey($s->cohort_id)->value('centre_id');
        $manages = Gate::allows('cohorts.manage') && $this->allowed($user, $centre, 'cohorts.manage');
        $teaches = Gate::allows('learning.deliver') && $s->facilitator_id === $user->id;
        abort_unless($manages || $teaches, 403, 'Only the facilitator of this class, or a manager, can do this.');

        return $s;
    }

    private function centre(Request $request, string $centreId, string $permission): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->allowed($user, $centreId, $permission), 404);
    }

    private function allowed(User $user, string $centreId, string $permission): bool
    {
        $centres = app(AccessService::class)->centresFor($user, $permission);

        return $centres === null || in_array($centreId, $centres, true);
    }

    private function attendance(): AttendanceService
    {
        return app(AttendanceService::class);
    }
}
