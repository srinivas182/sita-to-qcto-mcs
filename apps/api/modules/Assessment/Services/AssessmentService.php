<?php

declare(strict_types=1);

namespace Modules\Assessment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Assessment\Models\AssessmentSettings;
use Modules\Assessment\Models\Instrument;
use Modules\Assessment\Models\Result;
use Modules\Assessment\Support\ResultHash;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\Task;
use Modules\Delivery\Models\TaskAttempt;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Logbook\Models\LogbookSignoff;
use Modules\People\Services\CredentialService;
use Modules\Qualifications\Models\QualificationModule;

/**
 * Internal summative assessment: instruments per module (from the QAS) and the registered
 * assessor's decisions, Competent or Not yet competent, with criteria, evidence and feedback.
 * Results are never edited; a reassessment is a new attempt.
 */
final class AssessmentService
{
    public const TZ = 'Africa/Johannesburg';

    /** Which methods suit which kind of module. */
    public const METHODS_FOR = ['KM' => ['test', 'assignment', 'project'], 'PM' => ['observation', 'project', 'assignment'], 'WM' => ['logbook', 'observation']];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Replace a module's instruments. Instruments already used in a result are kept (made
     * inactive if left out) so old results still point at what was used.
     *
     * @param  array<string, mixed>  $input
     * @return Collection<int, Instrument>
     */
    public function saveInstruments(QualificationModule $module, array $input): Collection
    {
        $allowed = self::METHODS_FOR[$module->kind] ?? [];
        $data = Validator::make($input, [
            'instruments' => ['present', 'array', 'max:10'],
            'instruments.*.id' => ['nullable', 'ulid'],
            'instruments.*.method' => ['required', 'in:'.implode(',', $allowed)],
            'instruments.*.title' => ['required', 'string', 'min:2', 'max:200'],
            'instruments.*.criteria' => ['required', 'array', 'min:1', 'max:60'],
            'instruments.*.criteria.*.text' => ['required', 'string', 'min:2', 'max:500'],
            'instruments.*.criteria.*.key' => ['nullable', 'string', 'max:40'],
            'instruments.*.pass_mark' => ['nullable', 'integer', 'between:1,100'],
            'instruments.*.instructions' => ['nullable', 'string', 'max:3000'],
            'instruments.*.task_id' => ['nullable', 'ulid'],
        ], ['instruments.*.method.in' => 'Choose a method that suits a '.$module->kind.' module.'])->validate();

        return DB::transaction(function () use ($module, $data): Collection {
            $existing = Instrument::query()->where('module_id', $module->id)->get()->keyBy('id');
            $kept = [];
            foreach (array_values((array) $data['instruments']) as $i => $row) {
                if (($row['method'] === 'test') !== isset($row['pass_mark'])) {
                    throw ValidationException::withMessages(["instruments.{$i}.pass_mark" => $row['method'] === 'test' ? 'A test needs a pass mark.' : 'Only a test has a pass mark.']);
                }
                $criteria = [];
                foreach (array_values((array) $row['criteria']) as $n => $c) {
                    $criteria[] = ['key' => substr(Str::slug((string) ($c['key'] ?? '') ?: 'c'.($n + 1)), 0, 40), 'text' => trim((string) $c['text'])];
                }
                if (count(array_unique(array_column($criteria, 'key'))) !== count($criteria)) {
                    throw ValidationException::withMessages(["instruments.{$i}.criteria" => 'Each criterion needs its own key.']);
                }
                $taskId = $row['task_id'] ?? null;
                if ($taskId !== null) {
                    if (! in_array($row['method'], ['test', 'assignment'], true)) {
                        throw ValidationException::withMessages(["instruments.{$i}.task_id" => 'Only a test or an assignment can be taken online.']);
                    }
                    $ok = Task::query()->whereKey($taskId)->where('qualification_id', $module->qualification_id)->where('status', 'published')
                        ->where(fn ($q) => $q->whereNull('module_id')->orWhere('module_id', $module->id))->exists();
                    if (! $ok) {
                        throw ValidationException::withMessages(["instruments.{$i}.task_id" => 'Choose a published online task for this module.']);
                    }
                }
                $inst = (isset($row['id']) ? $existing->get($row['id']) : null) ?? new Instrument(['module_id' => $module->id]);
                if ($inst->exists && $inst->method !== $row['method'] && Result::query()->where('instrument_id', $inst->id)->exists()) {
                    throw ValidationException::withMessages(["instruments.{$i}.method" => 'This instrument has results; add a new one instead of changing its method.']);
                }
                $inst->fill(['method' => $row['method'], 'title' => trim((string) $row['title']), 'criteria' => $criteria, 'pass_mark' => $row['pass_mark'] ?? null,
                    'instructions' => $row['instructions'] ?? null, 'active' => true, 'sort' => $i, 'task_id' => $taskId])->save();
                $kept[] = $inst->id;
            }
            foreach ($existing->except($kept) as $old) {
                Result::query()->where('instrument_id', $old->id)->exists() ? $old->forceFill(['active' => false])->save() : $old->delete();
            }
            $this->audit->record('assessment.instruments_saved', $module, [], ['count' => count($kept)]);

            return Instrument::query()->where('module_id', $module->id)->where('active', true)->orderBy('sort')->get();
        });
    }

    /**
     * The assessor's decision. Checks their registration on the assessment date, the
     * learner's enrolment, the instrument, the competence rule and the attempts allowed.
     *
     * @param  array<string, mixed>  $input
     * @return array{result: Result, created: bool}
     */
    public function decide(User $assessor, array $input): array
    {
        $data = Validator::make($input, [
            'enrolment_id' => ['required', 'ulid'],
            'instrument_id' => ['required', 'ulid'],
            'outcome' => ['required', 'in:competent,nyc'],
            'score' => ['nullable', 'integer', 'between:0,100'],
            'criteria' => ['required', 'array'],
            'criteria.*.key' => ['required', 'string'],
            'criteria.*.met' => ['required', 'boolean'],
            'criteria.*.comment' => ['nullable', 'string', 'max:1000'],
            'file_ids' => ['nullable', 'array', 'max:10'],
            'file_ids.*' => ['ulid'],
            'feedback' => ['nullable', 'string', 'max:3000'],
            'remediation' => ['nullable', 'string', 'max:3000'],
            'assessed_on' => ['required', 'date_format:Y-m-d'],
            'client_id' => ['nullable', 'uuid'],
        ])->validate();
        if (isset($data['client_id'])) {
            $again = Result::query()->where('assessor_id', $assessor->id)->where('client_id', strtolower((string) $data['client_id']))->first();
            if ($again !== null) {
                return ['result' => $again, 'created' => false]; // a retry from the app's queue
            }
        }
        $enrolment = Enrolment::query()->whereKey($data['enrolment_id'])->whereIn('status', ['active', 'suspended', 'completed'])->first()
            ?? throw ValidationException::withMessages(['enrolment_id' => 'Choose an enrolled learner.']);
        $cohort = Cohort::query()->whereKey($enrolment->cohort_id)->firstOrFail();
        $instrument = Instrument::query()->whereKey($data['instrument_id'])->where('active', true)->first()
            ?? throw ValidationException::withMessages(['instrument_id' => 'Choose one of the module\'s assessments.']);
        $module = QualificationModule::query()->whereKey($instrument->module_id)->where('qualification_id', $cohort->qualification_id)->first()
            ?? throw ValidationException::withMessages(['instrument_id' => 'That assessment is not part of the learner\'s qualification.']);
        $on = CarbonImmutable::parse((string) $data['assessed_on'], self::TZ);
        if ($on->toDateString() > CarbonImmutable::now(self::TZ)->toDateString()) {
            throw ValidationException::withMessages(['assessed_on' => 'An assessment cannot be dated in the future.']);
        }
        if ($on->toDateString() < $enrolment->enrolled_on->toDateString()) {
            throw ValidationException::withMessages(['assessed_on' => 'The learner was not enrolled yet on that day.']);
        }
        $eligible = app(CredentialService::class)->eligibility($assessor->id, 'assessor', $cohort->qualification_id, $on);
        if (! $eligible['ok']) {
            throw ValidationException::withMessages(['assessor' => 'You cannot assess this qualification on that day: '.$eligible['reason']]);
        }

        // Criteria: every one answered, and the outcome follows the rule.
        $given = collect((array) $data['criteria'])->keyBy('key');
        $criteria = [];
        foreach ($instrument->criteria as $c) {
            $g = $given->get($c['key']) ?? throw ValidationException::withMessages(['criteria' => "Answer every criterion ({$c['text']})."]);
            $criteria[] = ['key' => $c['key'], 'met' => (bool) $g['met'], 'comment' => isset($g['comment']) ? trim((string) $g['comment']) : null];
        }
        $allMet = collect($criteria)->every(fn (array $c) => $c['met']);
        $score = isset($data['score']) ? (int) $data['score'] : null;
        if ($instrument->method === 'test') {
            if ($score === null) {
                throw ValidationException::withMessages(['score' => 'Enter the test score.']);
            }
            $competent = $score >= (int) $instrument->pass_mark && $allMet;
        } else {
            $score = null;
            $competent = $allMet;
        }
        if (($data['outcome'] === 'competent') !== $competent) {
            throw ValidationException::withMessages(['outcome' => $competent ? 'Every criterion is met, so the outcome is Competent.' : 'Not every criterion is met (or the score is below the pass mark), so the outcome is Not yet competent.']);
        }
        if ($data['outcome'] === 'nyc' && blank($data['feedback'] ?? null)) {
            throw ValidationException::withMessages(['feedback' => 'Tell the learner what to work on.']);
        }
        if ($instrument->method === 'logbook' && $data['outcome'] === 'competent'
            && ! LogbookSignoff::query()->where('enrolment_id', $enrolment->id)->where('module_id', $module->id)->where('status', 'checked')->exists()) {
            throw ValidationException::withMessages(['outcome' => 'The workplace logbook for this module has not been signed off by the mentor and checked yet.']);
        }
        $files = array_values(array_unique((array) ($data['file_ids'] ?? [])));
        if ($files !== [] && File::query()->whereIn('id', $files)->where('owner_id', $assessor->id)->where('purpose', 'assessment_evidence')->where('status', 'ready')->count() !== count($files)) {
            throw ValidationException::withMessages(['file_ids' => 'Upload the evidence again.']);
        }

        $result = DB::transaction(function () use ($assessor, $enrolment, $instrument, $module, $data, $criteria, $score, $files, $on, $eligible): Result {
            DB::table('enrolments')->where('id', $enrolment->id)->lockForUpdate()->value('id');
            $previous = Result::query()->where('enrolment_id', $enrolment->id)->where('instrument_id', $instrument->id)->whereIn('status', Result::STANDING)->orderByDesc('attempt')->first();
            if ($previous?->outcome === 'competent') {
                throw ValidationException::withMessages(['instrument_id' => 'The learner is already competent in this assessment.']);
            }
            $attempt = (int) Result::query()->where('enrolment_id', $enrolment->id)->where('instrument_id', $instrument->id)->max('attempt') + 1;
            $max = AssessmentSettings::current()->max_attempts;
            if ($attempt > $max) {
                throw ValidationException::withMessages(['instrument_id' => "The learner has had all {$max} attempts. Talk to the moderator about the next step."]);
            }
            $r = new Result(['enrolment_id' => $enrolment->id, 'user_id' => $enrolment->user_id, 'module_id' => $module->id, 'instrument_id' => $instrument->id, 'attempt' => $attempt,
                'assessor_id' => $assessor->id, 'credential_id' => $eligible['credential_id'], 'outcome' => $data['outcome'], 'score' => $score, 'criteria' => $criteria, 'file_ids' => $files,
                'feedback' => $data['feedback'] ?? null, 'remediation' => $data['remediation'] ?? null, 'assessed_on' => $on->toDateString(), 'decided_at' => now()->startOfSecond(),
                'client_id' => isset($data['client_id']) ? strtolower((string) $data['client_id']) : null]);
            $r->hash = ResultHash::of($r);
            $r->save();
            app(ModerationService::class)->consider($r); // in the sample, or final at once

            return $r->refresh();
        });
        $this->audit->record('assessment.decided', $result, [], ['outcome' => $result->outcome, 'attempt' => $result->attempt]);
        app(ResultNotifier::class)->decided($result, $module);

        return ['result' => $result, 'created' => true];
    }

    /**
     * The learner's online attempts at each linked task, for the assessor's decision.
     *
     * @param  iterable<Instrument>  $instruments
     * @return array<string, array{task_title: string, attempts: int, pass_pct: int, latest: array{number: int, score_pct: int|null, status: string, submitted_at: string}|null}>
     */
    public function online(Enrolment $e, iterable $instruments): array
    {
        $linked = [];
        foreach ($instruments as $i) {
            if ($i->task_id !== null) {
                $linked[$i->id] = $i->task_id;
            }
        }
        if ($linked === []) {
            return [];
        }
        $tasks = Task::query()->whereIn('id', array_values($linked))->get()->keyBy('id');
        $attempts = TaskAttempt::query()->where('enrolment_id', $e->id)->whereIn('task_id', array_values($linked))->orderBy('number')->get()->groupBy('task_id');
        $out = [];
        foreach ($linked as $instrumentId => $taskId) {
            $task = $tasks->get($taskId);
            if ($task === null) {
                continue;
            }
            $mine = $attempts->get($taskId);
            $latest = $mine?->last();
            $out[$instrumentId] = ['task_title' => $task->title, 'attempts' => $mine?->count() ?? 0, 'pass_pct' => $task->pass_pct,
                'latest' => $latest === null ? null : ['number' => $latest->number, 'score_pct' => $latest->score_pct, 'status' => $latest->status,
                    'submitted_at' => $latest->submitted_at->toIso8601String()]];
        }

        return $out;
    }

    /**
     * Per module of the learner's qualification: each instrument's latest standing result,
     * and the module's state (competent, nyc, in_progress, not_assessed).
     *
     * @return list<array<string, mixed>>
     */
    public function modules(Enrolment $e, string $qualificationId): array
    {
        return $this->modulesFor([$e], $qualificationId)[$e->id];
    }

    /**
     * modules() for many enrolments of one qualification in three queries, for cohort grids.
     *
     * @param  iterable<Enrolment>  $enrolments
     * @return array<string, list<array<string, mixed>>> keyed by enrolment id
     */
    public function modulesFor(iterable $enrolments, string $qualificationId): array
    {
        $ids = [];
        foreach ($enrolments as $e) {
            $ids[] = $e->id;
        }
        $modules = QualificationModule::query()->where('qualification_id', $qualificationId)->orderBy('sort')->get();
        $instruments = Instrument::query()->whereIn('module_id', $modules->pluck('id'))->where('active', true)->orderBy('sort')->get()->groupBy('module_id');
        $all = $ids === [] ? collect() : Result::query()->whereIn('enrolment_id', $ids)->whereIn('status', Result::STANDING)->orderBy('attempt')->get()
            ->groupBy(fn (Result $r) => $r->enrolment_id.'|'.$r->instrument_id);
        $out = [];
        foreach ($ids as $id) {
            $list = [];
            foreach ($modules as $m) {
                $rows = [];
                foreach ($instruments->get($m->id) ?? [] as $i) {
                    $mine = $all->get($id.'|'.$i->id);
                    $latest = $mine?->last();
                    $rows[] = ['instrument_id' => $i->id, 'title' => $i->title, 'method' => $i->method, 'attempts' => $mine?->count() ?? 0,
                        'latest' => $latest ? self::present($latest) : null];
                }
                $list[] = ['module_id' => $m->id, 'code' => $m->code, 'title' => $m->title, 'kind' => $m->kind, 'credits' => $m->credits,
                    'state' => self::state($rows), 'instruments' => $rows];
            }
            $out[$id] = $list;
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function state(array $rows): string
    {
        if ($rows === []) {
            return 'no_assessment';
        }
        $outcomes = array_map(fn (array $r) => $r['latest']['outcome'] ?? null, $rows);

        return match (true) {
            ! in_array(null, $outcomes, true) && ! in_array('nyc', $outcomes, true) => 'competent',
            in_array('nyc', $outcomes, true) => 'nyc',
            count(array_filter($outcomes)) > 0 => 'in_progress',
            default => 'not_assessed',
        };
    }

    /** @return array<string, mixed> */
    public static function present(Result $r): array
    {
        return ['id' => $r->id, 'enrolment_id' => $r->enrolment_id, 'module_id' => $r->module_id, 'instrument_id' => $r->instrument_id, 'attempt' => $r->attempt,
            'assessor_id' => $r->assessor_id, 'outcome' => $r->outcome, 'score' => $r->score, 'criteria' => $r->criteria, 'file_ids' => $r->file_ids,
            'feedback' => $r->feedback, 'remediation' => $r->remediation, 'assessed_on' => $r->assessed_on->toDateString(), 'decided_at' => $r->decided_at->toIso8601String(),
            'status' => $r->status, 'intact' => ResultHash::intact($r)];
    }
}
