<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Delivery\Models\Task;
use Modules\Delivery\Models\TaskAttempt;
use Modules\Delivery\Services\TaskService;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\QualificationModule;

/** Formative tasks: built by staff, done by learners, marked by facilitators. */
final class TaskController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('materials.manage');
        $data = $request->validate(['qualification_id' => ['required', 'ulid']]);
        $tasks = Task::query()->where('qualification_id', $data['qualification_id'])->orderBy('title')->get();
        $counts = TaskAttempt::query()->whereIn('task_id', $tasks->pluck('id'))->selectRaw('task_id, count(*) as n')->groupBy('task_id')->pluck('n', 'task_id');

        return response()->json(['data' => $tasks->map(fn (Task $t) => $t->only(['id', 'module_id', 'title', 'instructions', 'questions', 'evidence', 'evidence_hint', 'pass_pct', 'attempts', 'status'])
            + ['out_of' => $t->outOf(), 'answered' => (int) ($counts[$t->id] ?? 0)])->values()->all(),
            'modules' => QualificationModule::query()->where('qualification_id', $data['qualification_id'])->whereIn('kind', ['KM', 'PM'])->orderBy('sort')->get(['id', 'code', 'title', 'kind'])->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('materials.manage');
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => $this->tasks()->save(null, $request->all(), $by)], 201);
    }

    public function update(Request $request, string $task): JsonResponse
    {
        Gate::authorize('materials.manage');
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => $this->tasks()->save(Task::query()->whereKey($task)->firstOrFail(), $request->all(), $by)]);
    }

    public function publish(Request $request, string $task): JsonResponse
    {
        Gate::authorize('materials.manage');
        $data = $request->validate(['published' => ['required', 'boolean']]);

        return response()->json(['data' => $this->tasks()->publish(Task::query()->whereKey($task)->firstOrFail(), (bool) $data['published'])]);
    }

    /** A copy to change questions that learners have already answered. */
    public function copy(Request $request, string $task): JsonResponse
    {
        Gate::authorize('materials.manage');
        $t = Task::query()->whereKey($task)->firstOrFail();
        /** @var User $by */
        $by = $request->user();
        $copy = $this->tasks()->save(null, ['qualification_id' => $t->qualification_id, 'module_id' => $t->module_id, 'title' => $t->title.' (copy)', 'instructions' => $t->instructions,
            'questions' => $t->questions, 'evidence' => $t->evidence, 'evidence_hint' => $t->evidence_hint, 'pass_pct' => $t->pass_pct, 'attempts' => $t->attempts], $by);

        return response()->json(['data' => $copy], 201);
    }

    public function forCourse(Request $request, string $enrolment): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $e = Enrolment::query()->whereKey($enrolment)->where('user_id', $me->id)->where('status', 'active')->firstOrFail();

        return response()->json(['data' => $this->tasks()->forEnrolment($e)]);
    }

    public function submit(Request $request, string $task): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $a = $this->tasks()->submit(Task::query()->whereKey($task)->firstOrFail(), $me, $request->all());

        return response()->json(['data' => TaskService::attempt($a)], 201);
    }

    public function queue(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tasks()->queue($this->cohortsFor($request))]);
    }

    public function attempt(Request $request, string $attempt): JsonResponse
    {
        $a = $this->markable($request, $attempt);
        $t = Task::query()->whereKey($a->task_id)->firstOrFail();
        $learner = User::query()->whereKey($a->user_id)->first();

        return response()->json(['data' => TaskService::attempt($a) + [
            'task' => $t->only(['id', 'title', 'instructions', 'questions', 'evidence', 'evidence_hint', 'pass_pct']) + ['out_of' => $t->outOf()],
            'learner' => $learner?->fullName(),
            'files' => File::query()->whereIn('id', $a->file_ids)->get(['id', 'original_name', 'mime', 'size'])->values()->all(),
        ]]);
    }

    public function mark(Request $request, string $attempt): JsonResponse
    {
        $a = $this->markable($request, $attempt);
        $data = $request->validate(['marks' => ['present', 'array'], 'marks.*' => ['integer'], 'feedback' => ['required', 'string', 'min:3', 'max:3000']],
            ['feedback.required' => 'Write feedback for the learner.']);
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => TaskService::attempt($this->tasks()->mark($a, array_map('intval', (array) $data['marks']), $data['feedback'], $by))]);
    }

    public function results(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = Cohort::query()->whereKey($cohort)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, 'cohorts.view');
        abort_unless($centres === null || in_array($c->centre_id, $centres, true), 404);

        return response()->json(['data' => $this->tasks()->results($c)]);
    }

    /** @return list<string>|null cohorts whose attempts this person marks (null: all) */
    private function cohortsFor(Request $request): ?array
    {
        /** @var User $user */
        $user = $request->user();
        if (Gate::allows('cohorts.manage')) {
            $centres = app(AccessService::class)->centresFor($user, 'cohorts.manage');

            return $centres === null ? null : array_values(Cohort::query()->whereIn('centre_id', $centres)->pluck('id')->all());
        }
        abort_unless(Gate::allows('learning.deliver'), 403);

        return array_values(CohortStaff::query()->where('user_id', $user->id)->where('role', 'facilitator')->pluck('cohort_id')->all());
    }

    private function markable(Request $request, string $id): TaskAttempt
    {
        $a = TaskAttempt::query()->whereKey($id)->firstOrFail();
        $cohorts = $this->cohortsFor($request);
        if ($cohorts !== null) {
            abort_unless(in_array(Enrolment::query()->whereKey($a->enrolment_id)->value('cohort_id'), $cohorts, true), 404);
        }

        return $a;
    }

    private function tasks(): TaskService
    {
        return app(TaskService::class);
    }
}
