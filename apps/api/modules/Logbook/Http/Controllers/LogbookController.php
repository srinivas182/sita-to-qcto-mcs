<?php

declare(strict_types=1);

namespace Modules\Logbook\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\Placement;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Models\LogbookRule;
use Modules\Logbook\Services\LogbookService;
use Modules\Qualifications\Models\QualificationModule;

/** Logbook rules, learners' placements, the learner's own logbook, and the provider's view. */
final class LogbookController
{
    /** The work experience modules of a qualification with their logbook rules. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $data = $request->validate(['qualification_id' => ['required', 'ulid']]);
        $modules = QualificationModule::query()->where('qualification_id', $data['qualification_id'])->where('kind', 'WM')->orderBy('sort')->get();
        $rules = LogbookRule::query()->whereIn('module_id', $modules->pluck('id'))->get()->keyBy('module_id');

        return response()->json(['data' => $modules->map(fn (QualificationModule $m) => $m->only(['id', 'code', 'title', 'credits']) + [
            'default_hours' => $m->credits * 10, 'rule' => $rules->get($m->id)?->only(['activities', 'hours_needed', 'evidence_note'])])->values()->all()]);
    }

    public function rules(string $module): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $m = QualificationModule::query()->whereKey($module)->firstOrFail();
        $r = LogbookRule::query()->where('module_id', $m->id)->first();

        return response()->json(['data' => ['module' => $m->only(['id', 'code', 'title', 'kind', 'credits', 'workplace_criteria']),
            'rule' => $r?->only(['activities', 'hours_needed', 'evidence_note']), 'default_hours' => $m->credits * 10]]);
    }

    public function saveRules(Request $request, string $module): JsonResponse
    {
        Gate::authorize('cohorts.manage');

        return response()->json(['data' => $this->logbook()->saveRule(QualificationModule::query()->whereKey($module)->firstOrFail(), $request->all())]);
    }

    public function placements(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = $this->cohort($request, $cohort, 'cohorts.view');
        $enrolments = Enrolment::query()->where('cohort_id', $c->id)->whereIn('status', ['active', 'suspended'])->get();
        $people = User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id');
        $siteIds = Placement::query()->where('cohort_id', $c->id)->pluck('site_id');
        $sites = EmployerSite::query()->whereIn('id', $siteIds)->get();
        $employers = Employer::query()->whereIn('id', $sites->pluck('employer_id'))->pluck('legal_name', 'id');
        $mentors = Mentor::query()->where('active', true)->get()->filter(fn (Mentor $m) => array_intersect($m->site_ids, $siteIds->all()) !== []);
        $rows = LearnerPlacement::query()->where('cohort_id', $c->id)->orderBy('starts_on')->get();

        return response()->json(['data' => $rows->map(fn (LearnerPlacement $p) => $p->only(['id', 'enrolment_id', 'user_id', 'site_id', 'mentor_id']) + [
            'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'learner' => $people->get($p->user_id)?->fullName(),
            'site' => $sites->firstWhere('id', $p->site_id)?->name, 'mentor' => $mentors->firstWhere('id', $p->mentor_id)?->name,
        ])->values()->all(), 'options' => [
            'learners' => $enrolments->map(fn (Enrolment $e) => ['enrolment_id' => $e->id, 'name' => $people->get($e->user_id)?->fullName()])->values()->all(),
            'sites' => $sites->map(fn (EmployerSite $s) => ['id' => $s->id, 'name' => $s->name, 'employer' => $employers->get($s->employer_id)])->values()->all(),
            'mentors' => $mentors->map(fn (Mentor $m) => ['id' => $m->id, 'name' => $m->name, 'site_ids' => $m->site_ids])->values()->all(),
        ]]);
    }

    public function place(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');

        return response()->json(['data' => $this->logbook()->place($this->cohort($request, $cohort, 'cohorts.manage'), $request->all())], 201);
    }

    public function endPlacement(Request $request, string $placement): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $data = $request->validate(['ends_on' => ['required', 'date']]);
        $p = LearnerPlacement::query()->whereKey($placement)->firstOrFail();
        $this->cohort($request, $p->cohort_id, 'cohorts.manage');

        return response()->json(['data' => $this->logbook()->endPlacement($p, (string) $data['ends_on'])]);
    }

    /** The learner's logbook: where they are placed, the modules and activities, hours, and recent entries. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $placements = LearnerPlacement::query()->where('user_id', $me->id)->orderByDesc('starts_on')->get();
        $cohorts = Cohort::query()->whereIn('id', $placements->pluck('cohort_id'))->get()->keyBy('id');
        $sites = EmployerSite::query()->whereIn('id', $placements->pluck('site_id'))->get()->keyBy('id');
        $mentors = Mentor::query()->whereIn('id', $placements->pluck('mentor_id')->filter())->pluck('name', 'id');
        $qualification = $cohorts->first()?->qualification_id;
        $modules = $qualification ? QualificationModule::query()->where('qualification_id', $qualification)->where('kind', 'WM')->orderBy('sort')->get() : collect();
        $rules = LogbookRule::query()->whereIn('module_id', $modules->pluck('id'))->get()->keyBy('module_id');
        $last = LogbookEntry::query()->where('user_id', $me->id)->orderByDesc('seq')->first();

        return response()->json(['data' => [
            'placements' => $placements->map(fn (LearnerPlacement $p) => ['id' => $p->id, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on?->toDateString(),
                'site' => $sites->get($p->site_id)?->name, 'mentor' => $mentors->get($p->mentor_id), 'cohort' => $cohorts->get($p->cohort_id)?->name])->values()->all(),
            'modules' => $modules->filter(fn (QualificationModule $m) => $rules->has($m->id))->map(fn (QualificationModule $m) => ['id' => $m->id, 'code' => $m->code, 'title' => $m->title,
                'activities' => $rules->get($m->id)?->activities, 'hours_needed' => $rules->get($m->id)?->hours_needed, 'evidence_note' => $rules->get($m->id)?->evidence_note])->values()->all(),
            'hours' => $qualification ? $this->logbook()->hours($me->id, $qualification) : [],
            'last_hash' => $last?->hash,
            'entries' => LogbookEntry::query()->where('user_id', $me->id)->orderByDesc('seq')->limit(100)->get()->map(fn (LogbookEntry $e) => LogbookService::present($e))->values()->all(),
        ]]);
    }

    public function record(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $r = $this->logbook()->record($me, $request->all());

        return response()->json(['data' => LogbookService::present($r['entry'])], $r['created'] ? 201 : 200);
    }

    public function correct(Request $request, string $entry): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $e = LogbookEntry::query()->whereKey($entry)->where('user_id', $me->id)->firstOrFail();

        return response()->json(['data' => LogbookService::present($this->logbook()->correct($e, $me, $request->all()))], 201);
    }

    /** Hours per learner and module for a cohort, the last entry, and whether each chain checks out. */
    public function summary(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = $this->cohort($request, $cohort, 'cohorts.view');
        $enrolments = Enrolment::query()->where('cohort_id', $c->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $people = User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id');
        $lastDates = LogbookEntry::query()->whereIn('user_id', $enrolments->pluck('user_id'))->whereIn('status', LogbookEntry::COUNTED)
            ->selectRaw('user_id, max(work_date) as last')->groupBy('user_id')->pluck('last', 'user_id');

        return response()->json(['data' => $enrolments->map(fn (Enrolment $e) => ['user_id' => $e->user_id, 'name' => $people->get($e->user_id)?->fullName(),
            'hours' => $this->logbook()->hours($e->user_id, $c->qualification_id), 'last_entry' => $lastDates->get($e->user_id),
            'chain' => $this->logbook()->verify($e->user_id)])->sortBy('name')->values()->all()]);
    }

    /** One learner's entries, for the provider. */
    public function learner(Request $request, string $cohort, string $user): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = $this->cohort($request, $cohort, 'cohorts.view');
        abort_unless(Enrolment::query()->where('cohort_id', $c->id)->where('user_id', $user)->exists(), 404);

        return response()->json(['data' => [
            'entries' => LogbookEntry::query()->where('user_id', $user)->orderByDesc('seq')->limit(500)->get()->map(fn (LogbookEntry $e) => LogbookService::present($e))->values()->all(),
            'hours' => $this->logbook()->hours($user, $c->qualification_id), 'chain' => $this->logbook()->verify($user),
        ]]);
    }

    private function cohort(Request $request, string $id, string $permission): Cohort
    {
        $c = Cohort::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($c->centre_id, $centres, true), 404);

        return $c;
    }

    private function logbook(): LogbookService
    {
        return app(LogbookService::class);
    }
}
