<?php

declare(strict_types=1);

namespace Modules\Assessment\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Assessment\Models\Appeal;
use Modules\Assessment\Models\AssessmentSettings;
use Modules\Assessment\Models\Instrument;
use Modules\Assessment\Models\Result;
use Modules\Assessment\Services\AssessmentService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;

/** Instruments per module, the provider's assessment policy, results per cohort, and the learner's own results. */
final class AssessmentController
{
    /** Who may see a cohort's results: staff with cohorts.view, its assessors and its moderators. */
    private const VIEW = ['cohorts.view', 'assessment.conduct', 'assessment.moderate'];

    public function instruments(string $module): JsonResponse
    {
        Gate::authorize('programmes.view');
        $m = QualificationModule::query()->whereKey($module)->firstOrFail();

        return response()->json(['data' => ['module' => $m->only(['id', 'code', 'title', 'kind', 'credits']), 'methods' => AssessmentService::METHODS_FOR[$m->kind] ?? [],
            'instruments' => Instrument::query()->where('module_id', $m->id)->where('active', true)->orderBy('sort')->get()
                ->map(fn (Instrument $i) => $i->only(['id', 'method', 'title', 'criteria', 'pass_mark', 'instructions']))->values()->all()]]);
    }

    public function saveInstruments(Request $request, string $module): JsonResponse
    {
        Gate::authorize('programmes.manage');
        $saved = app(AssessmentService::class)->saveInstruments(QualificationModule::query()->whereKey($module)->firstOrFail(), $request->all());

        return response()->json(['data' => $saved->map(fn (Instrument $i) => $i->only(['id', 'method', 'title', 'criteria', 'pass_mark', 'instructions']))->values()->all()]);
    }

    /** The modules of a qualification with how many instruments each has. */
    public function plan(Request $request): JsonResponse
    {
        Gate::authorize('programmes.view');
        $data = $request->validate(['qualification_id' => ['required', 'ulid']]);
        $modules = QualificationModule::query()->where('qualification_id', $data['qualification_id'])->orderBy('sort')->get();
        $counts = Instrument::query()->whereIn('module_id', $modules->pluck('id'))->where('active', true)->selectRaw('module_id, count(*) as n')->groupBy('module_id')->pluck('n', 'module_id');

        return response()->json(['data' => $modules->map(fn (QualificationModule $m) => $m->only(['id', 'code', 'title', 'kind', 'credits']) + ['instruments' => (int) ($counts[$m->id] ?? 0)])->values()->all()]);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('programmes.view');

        return response()->json(['data' => $this->settingsData()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        Gate::authorize('tenant.settings.manage');
        $data = $request->validate([
            'max_attempts' => ['sometimes', 'integer', 'between:1,6'],
            'random_pct' => ['sometimes', 'integer', 'between:10,100'],
            'new_assessor_first' => ['sometimes', 'integer', 'between:0,50'],
            'appeal_days' => ['sometimes', 'integer', 'between:5,60'],
            'appeal_decide_days' => ['sometimes', 'integer', 'between:5,90'],
        ]);
        $s = AssessmentSettings::current();
        $s->fill($data)->save();

        return response()->json(['data' => $this->settingsData()]);
    }

    /** Learners × modules for a cohort: each module's state. */
    public function cohort(Request $request, string $cohort): JsonResponse
    {
        $c = $this->cohortFor($request, $cohort);
        $enrolments = Enrolment::query()->where('cohort_id', $c->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $people = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id'));
        $svc = app(AssessmentService::class);
        $modules = QualificationModule::query()->where('qualification_id', $c->qualification_id)->orderBy('sort')->get(['id', 'code', 'title', 'kind']);
        $states = $svc->modulesFor($enrolments, $c->qualification_id);

        return response()->json(['data' => [
            'modules' => $modules->map(fn (QualificationModule $m) => $m->only(['id', 'code', 'title', 'kind']))->values()->all(),
            'learners' => $enrolments->map(fn (Enrolment $e) => ['enrolment_id' => $e->id, 'user_id' => $e->user_id, 'name' => $people->get($e->user_id)?->fullName(),
                'modules' => array_map(fn (array $m) => ['module_id' => $m['module_id'], 'state' => $m['state']], $states[$e->id])])
                ->sortBy('name')->values()->all(),
        ]]);
    }

    /** One learner's modules, instruments, attempts and results, for assessors and staff. */
    public function learner(Request $request, string $cohort, string $enrolment): JsonResponse
    {
        $c = $this->cohortFor($request, $cohort);
        $e = Enrolment::query()->whereKey($enrolment)->where('cohort_id', $c->id)->firstOrFail();
        $learner = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereKey($e->user_id)->first());
        $instruments = Instrument::query()->whereIn('module_id', QualificationModule::query()->where('qualification_id', $c->qualification_id)->pluck('id'))->where('active', true)->orderBy('sort')->get();

        return response()->json(['data' => [
            'enrolment_id' => $e->id, 'name' => $learner?->fullName(),
            'modules' => app(AssessmentService::class)->modules($e, $c->qualification_id),
            'instruments' => $instruments->map(fn (Instrument $i) => $i->only(['id', 'module_id', 'method', 'title', 'criteria', 'pass_mark', 'instructions']))->values()->all(),
            'history' => Result::query()->where('enrolment_id', $e->id)->orderByDesc('decided_at')->get()->map(fn (Result $r) => AssessmentService::present($r))->values()->all(),
            'max_attempts' => AssessmentSettings::current()->max_attempts,
        ]]);
    }

    public function decide(Request $request): JsonResponse
    {
        Gate::authorize('assessment.conduct');
        /** @var User $me */
        $me = $request->user();
        $e = Enrolment::query()->whereKey((string) $request->input('enrolment_id'))->first();
        if ($e !== null) {
            $this->cohortFor($request, $e->cohort_id, 'assessment.conduct');
        }
        $r = app(AssessmentService::class)->decide($me, $request->all());

        return response()->json(['data' => AssessmentService::present($r['result'])], $r['created'] ? 201 : 200);
    }

    /** Cohorts an assessor or moderator works on: those at their centres that are running or recently ended. */
    public function assessing(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $access = app(AccessService::class);
        $perms = array_values(array_filter(['assessment.conduct', 'assessment.moderate'], fn (string $p) => Gate::allows($p)));
        abort_if($perms === [], 403);
        $all = false;
        $centres = [];
        foreach ($perms as $p) {
            $c = $access->centresFor($me, $p);
            $c === null ? $all = true : $centres = array_merge($centres, $c);
        }
        $cohorts = Cohort::query()->when(! $all, fn ($q) => $q->whereIn('centre_id', $centres))->where('status', '!=', 'cancelled')
            ->orderByDesc('intake_on')->limit(100)->get();
        $counts = Enrolment::query()->whereIn('cohort_id', $cohorts->pluck('id'))->whereIn('status', ['active', 'suspended', 'completed'])
            ->selectRaw('cohort_id, count(*) as n')->groupBy('cohort_id')->pluck('n', 'cohort_id');

        return response()->json(['data' => $cohorts->map(fn (Cohort $c) => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'learners' => (int) ($counts[$c->id] ?? 0)])
            ->filter(fn (array $c) => $c['learners'] > 0)->values()->all()]);
    }

    /** The learner's own results per course: modules, latest outcomes, feedback. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $svc = app(AssessmentService::class);
        $enrolments = Enrolment::query()->where('user_id', $me->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $cohorts = Cohort::query()->whereIn('id', $enrolments->pluck('cohort_id'))->get()->keyBy('id');
        $appeals = Appeal::query()->where('user_id', $me->id)->get()->keyBy('result_id');
        $days = AssessmentSettings::current()->appeal_days;
        $mineOf = fn (array $latest) => array_diff_key($latest, ['assessor_id' => 1, 'intact' => 1]) + [
            'appeal' => ($a = $appeals->get($latest['id'])) ? ModerationController::presentAppeal($a) : null,
            'appeal_until' => CarbonImmutable::parse($latest['decided_at'])->addDays($days)->toIso8601String(),
            'can_appeal' => ! $appeals->has($latest['id']) && CarbonImmutable::parse($latest['decided_at'])->addDays($days)->isFuture(),
        ];

        return response()->json(['data' => $enrolments->map(fn (Enrolment $e) => ['enrolment_id' => $e->id, 'cohort' => $cohorts->get($e->cohort_id)?->name,
            'modules' => array_map(fn (array $m) => array_merge($m, ['instruments' => array_map(fn (array $i) => array_merge($i, ['latest' => $i['latest'] === null ? null
                : $mineOf($i['latest'])]), $m['instruments'])]),
                $svc->modules($e, (string) $cohorts->get($e->cohort_id)?->qualification_id))])->values()->all()]);
    }

    /** @return array<string, int> */
    private function settingsData(): array
    {
        return AssessmentSettings::current()->only(['max_attempts', 'random_pct', 'new_assessor_first', 'appeal_days', 'appeal_decide_days']);
    }

    private function cohortFor(Request $request, string $id, ?string $permission = null): Cohort
    {
        $c = Cohort::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $perms = $permission ? [$permission] : array_values(array_filter(self::VIEW, fn (string $p) => Gate::allows($p)));
        abort_if($perms === [], 403);
        $access = app(AccessService::class);
        foreach ($perms as $p) {
            $centres = $access->centresFor($user, $p);
            if ($centres === null || in_array($c->centre_id, $centres, true)) {
                return $c;
            }
        }
        abort(404);
    }
}
