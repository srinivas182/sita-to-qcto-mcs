<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\Task;
use Modules\Delivery\Models\TaskAttempt;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Services\EnrolmentService;
use Modules\Files\Models\File;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;
use Throwable;

/**
 * Formative tasks per curriculum module: multiple-choice questions marked at once, short
 * answers and evidence from the phone (photos, video, a PDF) marked by the facilitator with
 * feedback. Results feed the Statement of Results later.
 */
final class TaskService
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context, private readonly PushService $pushes) {}

    /** @param  array<string, mixed>  $input */
    public function save(?Task $task, array $input, User $by): Task
    {
        $creating = $task === null;
        $data = Validator::make($input, [
            'qualification_id' => [$creating ? 'required' : 'prohibited', 'ulid'],
            'module_id' => ['nullable', 'ulid'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'questions' => [$creating ? 'present' : 'sometimes', 'array', 'max:50'],
            'questions.*.type' => ['required', 'in:single,multi,short'],
            'questions.*.text' => ['required', 'string', 'min:2', 'max:1000'],
            'questions.*.options' => ['required_unless:questions.*.type,short', 'array', 'min:2', 'max:8'],
            'questions.*.options.*' => ['string', 'max:300'],
            'questions.*.correct' => ['required_unless:questions.*.type,short', 'array', 'min:1'],
            'questions.*.correct.*' => ['integer', 'min:0'],
            'questions.*.marks' => ['required', 'integer', 'between:1,20'],
            'questions.*.guide' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['sometimes', 'boolean'],
            'evidence_hint' => ['nullable', 'string', 'max:300'],
            'pass_pct' => ['sometimes', 'integer', 'between:1,100'],
            'attempts' => ['sometimes', 'integer', 'between:1,10'],
        ])->validate();
        $t = $task ?? new Task(['created_by' => $by->id]);
        $qualification = $creating ? (string) $data['qualification_id'] : $t->qualification_id;
        if (! empty($data['module_id']) && ! QualificationModule::query()->whereKey($data['module_id'])->where('qualification_id', $qualification)->exists()) {
            throw ValidationException::withMessages(['module_id' => 'Choose a module of this qualification.']);
        }
        if (isset($data['questions'])) {
            if (! $creating && TaskAttempt::query()->where('task_id', $t->id)->exists()) {
                throw ValidationException::withMessages(['questions' => 'Learners have already answered this task. Make a copy to change the questions.']);
            }
            $questions = [];
            foreach (array_values((array) $data['questions']) as $i => $q) {
                $item = ['id' => 'q'.($i + 1), 'type' => $q['type'], 'text' => trim((string) $q['text']), 'marks' => (int) $q['marks']];
                if ($q['type'] !== 'short') {
                    $options = array_values(array_map('trim', (array) $q['options']));
                    $correct = array_values(array_unique(array_map('intval', (array) $q['correct'])));
                    if ($correct === [] || max($correct) >= count($options) || ($q['type'] === 'single' && count($correct) !== 1)) {
                        throw ValidationException::withMessages(["questions.{$i}.correct" => 'Mark the right answer'.($q['type'] === 'multi' ? 's' : '').' for question '.($i + 1).'.']);
                    }
                    $item += ['options' => $options, 'correct' => $correct];
                } else {
                    $item['guide'] = $q['guide'] ?? null;
                }
                $questions[] = $item;
            }
            $data['questions'] = $questions;
        }
        $t->fill($data);
        if (($t->getAttribute('questions') ?? []) === [] && ! $t->evidence) {
            throw ValidationException::withMessages(['questions' => 'Add at least one question, or ask for evidence.']);
        }
        $t->save();
        $this->audit->record($creating ? 'task.created' : 'task.changed', $t);

        return $t;
    }

    public function publish(Task $t, bool $published): Task
    {
        $t->forceFill(['status' => $published ? 'published' : 'draft'])->save();
        $this->audit->record($published ? 'task.published' : 'task.unpublished', $t);

        return $t;
    }

    /**
     * What a learner sees: the questions without answers, and their own attempts.
     *
     * @param  list<array<string, mixed>>|null  $attempts
     * @return array<string, mixed>
     */
    public static function forLearner(Task $t, ?array $attempts = null): array
    {
        return ['id' => $t->id, 'module_id' => $t->module_id, 'title' => $t->title, 'instructions' => $t->instructions, 'evidence' => $t->evidence,
            'evidence_hint' => $t->evidence_hint, 'pass_pct' => $t->pass_pct, 'attempts_allowed' => $t->attempts, 'out_of' => $t->outOf(),
            'questions' => array_map(fn (array $q) => array_intersect_key($q, array_flip(['id', 'type', 'text', 'options', 'marks'])), $t->questions)]
            + ($attempts !== null ? ['attempts' => $attempts] : []);
    }

    /** @return list<array<string, mixed>> */
    public function forEnrolment(Enrolment $e): array
    {
        $qualification = (string) Cohort::query()->whereKey($e->cohort_id)->value('qualification_id');
        $tasks = Task::query()->where('qualification_id', $qualification)->where('status', 'published')->orderBy('title')->get();
        $attempts = TaskAttempt::query()->where('user_id', $e->user_id)->whereIn('task_id', $tasks->pluck('id'))->orderBy('number')->get()->groupBy('task_id');

        return array_values($tasks->map(fn (Task $t) => self::forLearner($t, array_values(($attempts->get($t->id) ?? collect())->map(fn (TaskAttempt $a) => self::attempt($a))->all())))->all());
    }

    /**
     * A learner hands in an attempt (also sent later from the phone's outbox).
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(Task $t, User $learner, array $input): TaskAttempt
    {
        if ($t->status !== 'published' || ! EnrolmentService::mayLearn($learner->id, $t->qualification_id)) {
            abort(404);
        }
        $data = Validator::make($input, [
            'answers' => ['present', 'array'],
            'file_ids' => [$t->evidence ? 'required' : 'nullable', 'array', 'max:10'],
            'file_ids.*' => ['ulid'],
            'submitted_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(2)->toIso8601String()],
        ], ['file_ids.required' => 'Add your photos, video or document.'])->validate();
        $enrolment = Enrolment::query()->where('user_id', $learner->id)->where('status', 'active')
            ->whereIn('cohort_id', Cohort::query()->where('qualification_id', $t->qualification_id)->select('id'))->firstOrFail();
        $previous = TaskAttempt::query()->where('task_id', $t->id)->where('user_id', $learner->id)->orderByDesc('number')->get();
        if ($previous->contains('status', 'passed')) {
            throw ValidationException::withMessages(['task' => 'You have already passed this task.']);
        }
        if ($previous->contains('status', 'submitted')) {
            throw ValidationException::withMessages(['task' => 'Your last attempt is waiting to be marked.']);
        }
        if ($previous->count() >= $t->attempts) {
            throw ValidationException::withMessages(['task' => 'You have used all your attempts. Speak to your facilitator.']);
        }
        $files = array_values(array_unique((array) ($data['file_ids'] ?? [])));
        if ($files !== [] && File::query()->whereIn('id', $files)->where('owner_id', $learner->id)->where('purpose', 'task_evidence')->where('status', 'ready')->count() !== count($files)) {
            throw ValidationException::withMessages(['file_ids' => 'Upload your evidence again.']);
        }

        $answers = [];
        $auto = 0;
        foreach ($t->questions as $i => $q) {
            $given = $data['answers'][$q['id']] ?? null;
            if ($q['type'] === 'short') {
                $text = trim((string) $given);
                if ($text === '') {
                    throw ValidationException::withMessages(["answers.{$q['id']}" => 'Answer question '.($i + 1).'.']);
                }
                $answers[$q['id']] = mb_substr($text, 0, 5000);

                continue;
            }
            $picked = array_values(array_unique(array_map('intval', is_array($given) ? $given : ($given === null ? [] : [$given]))));
            sort($picked);
            if ($picked === [] || max($picked) >= count($q['options'] ?? [])) {
                throw ValidationException::withMessages(["answers.{$q['id']}" => 'Answer question '.($i + 1).'.']);
            }
            $correct = $q['correct'] ?? [];
            sort($correct);
            $answers[$q['id']] = $picked;
            $auto += $picked === $correct ? (int) $q['marks'] : 0;
        }

        $attempt = DB::transaction(function () use ($t, $learner, $enrolment, $previous, $answers, $files, $auto, $data): TaskAttempt {
            $a = new TaskAttempt(['task_id' => $t->id, 'user_id' => $learner->id, 'enrolment_id' => $enrolment->id, 'number' => $previous->count() + 1,
                'answers' => $answers, 'file_ids' => $files, 'auto_marks' => $auto, 'out_of' => $t->outOf(),
                'submitted_at' => isset($data['submitted_at']) ? CarbonImmutable::parse((string) $data['submitted_at']) : now(), 'offline' => isset($data['submitted_at'])]);
            if (! $t->needsMarking()) {
                $this->result($a, $t, $auto, null, null);
            }
            $a->save();

            return $a;
        });
        $this->audit->record('task.submitted', $attempt, [], ['number' => $attempt->number]);

        return $attempt;
    }

    /**
     * The facilitator marks short answers and evidence, and writes feedback.
     *
     * @param  array<string, int>  $marks  question id (or "evidence") => marks given
     */
    public function mark(TaskAttempt $a, array $marks, string $feedback, User $by): TaskAttempt
    {
        if ($a->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'This attempt has already been marked.']);
        }
        $t = Task::query()->whereKey($a->task_id)->firstOrFail();
        $total = $a->auto_marks;
        foreach ($t->questions as $q) {
            if ($q['type'] !== 'short') {
                continue;
            }
            $m = $marks[$q['id']] ?? null;
            if (! is_int($m) || $m < 0 || $m > $q['marks']) {
                throw ValidationException::withMessages(["marks.{$q['id']}" => "Give 0 to {$q['marks']} marks."]);
            }
            $total += $m;
        }
        if ($t->evidence) {
            $m = $marks['evidence'] ?? null;
            if (! is_int($m) || $m < 0 || $m > 10) {
                throw ValidationException::withMessages(['marks.evidence' => 'Give 0 to 10 marks for the evidence.']);
            }
            $total += $m;
        }
        $this->result($a, $t, $total, $feedback, $by);
        $a->save();
        $this->audit->record('task.marked', $a, [], ['score' => $a->score_pct, 'status' => $a->status]);
        $this->tell($a, $t);

        return $a;
    }

    /**
     * Attempts waiting to be marked: for a facilitator, the cohorts they are on; for a
     * manager, every cohort at their centres.
     *
     * @param  list<string>|null  $cohortIds  null for all
     * @return list<array<string, mixed>>
     */
    public function queue(?array $cohortIds): array
    {
        $q = TaskAttempt::query()->where('status', 'submitted')->orderBy('submitted_at');
        if ($cohortIds !== null) {
            $q->whereIn('enrolment_id', Enrolment::query()->whereIn('cohort_id', $cohortIds)->select('id'));
        }
        $rows = $q->limit(200)->get();
        $tasks = Task::query()->whereIn('id', $rows->pluck('task_id'))->get()->keyBy('id');
        $people = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id'));
        $cohorts = Cohort::query()->whereIn('id', Enrolment::query()->whereIn('id', $rows->pluck('enrolment_id'))->select('cohort_id'))->pluck('name', 'id');
        $enrolmentCohort = Enrolment::query()->whereIn('id', $rows->pluck('enrolment_id'))->pluck('cohort_id', 'id');

        return array_values($rows->map(fn (TaskAttempt $a) => self::attempt($a) + ['task' => $tasks->get($a->task_id)?->title, 'learner' => $people->get($a->user_id)?->fullName(),
            'cohort' => $cohorts->get($enrolmentCohort->get($a->enrolment_id))])->all());
    }

    /**
     * Best result per learner and task in a cohort.
     *
     * @return array{tasks: list<array<string, mixed>>, learners: list<array<string, mixed>>}
     */
    public function results(Cohort $cohort): array
    {
        $tasks = Task::query()->where('qualification_id', $cohort->qualification_id)->where('status', 'published')->orderBy('title')->get();
        $enrolments = Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $attempts = TaskAttempt::query()->whereIn('enrolment_id', $enrolments->pluck('id'))->get()->groupBy(fn (TaskAttempt $a) => $a->user_id.'|'.$a->task_id);
        $people = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id'));
        $modules = QualificationModule::query()->whereIn('id', $tasks->pluck('module_id')->filter())->pluck('code', 'id');

        $rows = [];
        foreach ($enrolments as $e) {
            $cells = [];
            foreach ($tasks as $t) {
                $mine = $attempts->get($e->user_id.'|'.$t->id) ?? collect();
                $best = $mine->firstWhere('status', 'passed') ?? $mine->sortByDesc('number')->first();
                $cells[$t->id] = $best ? ['status' => $best->status, 'score_pct' => $best->score_pct, 'attempts' => $mine->count()] : null;
            }
            $rows[] = ['user_id' => $e->user_id, 'name' => $people->get($e->user_id)?->fullName(), 'results' => $cells,
                'passed' => count(array_filter($cells, fn ($c) => ($c['status'] ?? null) === 'passed'))];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return ['tasks' => array_values($tasks->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'module' => $modules->get($t->module_id)])->all()), 'learners' => $rows];
    }

    /** @return array<string, mixed> */
    public static function attempt(TaskAttempt $a): array
    {
        return ['id' => $a->id, 'task_id' => $a->task_id, 'number' => $a->number, 'status' => $a->status, 'score_pct' => $a->score_pct, 'marks' => $a->marks,
            'out_of' => $a->out_of, 'feedback' => $a->feedback, 'answers' => $a->answers, 'file_ids' => $a->file_ids, 'offline' => $a->offline,
            'submitted_at' => $a->submitted_at->toIso8601String(), 'marked_at' => $a->marked_at?->toIso8601String()];
    }

    private function result(TaskAttempt $a, Task $t, int $marks, ?string $feedback, ?User $by): void
    {
        $pct = $a->out_of > 0 ? (int) round($marks * 100 / $a->out_of) : 0;
        $a->forceFill(['marks' => $marks, 'score_pct' => $pct, 'status' => $pct >= $t->pass_pct ? 'passed' : 'not_yet', 'feedback' => $feedback,
            'marked_by' => $by?->id, 'marked_at' => now()]);
    }

    private function tell(TaskAttempt $a, Task $t): void
    {
        foreach (Device::query()->where('user_id', $a->user_id)->whereNull('revoked_at')->whereNotNull('push_token')->get() as $d) {
            try {
                $this->pushes->send($d, $a->status === 'passed' ? 'Task passed' : 'Task marked', "{$t->title}: {$a->score_pct}%", ['type' => 'task', 'task_id' => $t->id]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
