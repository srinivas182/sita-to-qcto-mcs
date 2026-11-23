<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Models\Material;
use Modules\Delivery\Models\MaterialProgress;
use Modules\Delivery\Models\RegisterSignature;
use Modules\Delivery\Models\Task;
use Modules\Delivery\Models\TaskAttempt;
use Modules\Delivery\Services\AttendanceService;
use Modules\Delivery\Services\TimetableService;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;

/** The learner's day in one small response (for phones on little data). */
final class DashboardController
{
    public function __invoke(Request $request, AttendanceService $attendance): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $enrolments = Enrolment::query()->where('user_id', $me->id)->where('status', 'active')->get();
        $courses = [];
        foreach ($enrolments as $e) {
            $cohort = Cohort::query()->whereKey($e->cohort_id)->first();
            if ($cohort === null) {
                continue;
            }
            $row = collect($attendance->report($cohort, CarbonImmutable::parse($e->enrolled_on, TimetableService::TZ)->startOfDay(), CarbonImmutable::now(TimetableService::TZ))['learners'])
                ->firstWhere('user_id', $me->id);
            $materials = Material::query()->where('qualification_id', $cohort->qualification_id)->where('status', 'published')->pluck('id');
            $tasks = Task::query()->where('qualification_id', $cohort->qualification_id)->where('status', 'published')->pluck('id');
            $attempts = TaskAttempt::query()->where('user_id', $me->id)->whereIn('task_id', $tasks)->get();
            $courses[] = [
                'enrolment_id' => $e->id, 'cohort' => $cohort->name, 'title' => Qualification::query()->whereKey($cohort->qualification_id)->value('title'),
                'attendance' => ['pct' => $row['pct'] ?? null, 'held' => $row['held'] ?? 0, 'attended' => $row['attended'] ?? 0, 'at_risk' => $row['at_risk'] ?? false],
                'materials' => ['total' => $materials->count(), 'done' => MaterialProgress::query()->where('user_id', $me->id)->whereIn('material_id', $materials)->whereNotNull('completed_at')->count()],
                'tasks' => ['total' => $tasks->count(), 'passed' => $attempts->where('status', 'passed')->pluck('task_id')->unique()->count(),
                    'waiting' => $attempts->where('status', 'submitted')->count()],
            ];
        }
        $from = CarbonImmutable::now(TimetableService::TZ)->startOfDay();
        $today = ClassSession::query()->where(fn ($q) => $q->whereIn('cohort_id', $enrolments->pluck('cohort_id'))->orWhere('facilitator_id', $me->id))
            ->whereBetween('starts_at', [$from->utc(), $from->endOfDay()->utc()])->orderBy('starts_at')->get();

        return response()->json(['data' => [
            'courses' => $courses,
            'today' => array_values($today->map(fn (ClassSession $s) => TimetableService::present($s) + ['teaching' => $s->facilitator_id === $me->id])->all()),
            'registers_to_sign' => RegisterSignature::query()->where('user_id', $me->id)->where('status', 'pending')->count(),
        ]]);
    }
}
