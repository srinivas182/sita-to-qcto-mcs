<?php

declare(strict_types=1);

namespace Modules\Cohorts\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Cohorts\Models\Placement;
use Modules\Cohorts\Services\CohortService;
use Modules\Cohorts\Services\Readiness;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Models\User;
use Modules\People\Models\StaffCredential;
use Modules\People\Services\CredentialService;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Models\Centre;

/** Cohorts: plan, check readiness, open, and follow through to the end. */
final class CohortController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $query = Cohort::query()->orderByDesc('intake_on');
        if (($centres = $this->centres($request, 'cohorts.view')) !== null) {
            $query->whereIn('centre_id', $centres);
        }
        if (in_array($status = (string) $request->query('status'), ['draft', 'open', 'closed', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        $rows = $query->limit(300)->get();
        $quals = Qualification::query()->whereIn('id', $rows->pluck('qualification_id'))->pluck('title', 'id');
        $centreNames = Centre::query()->whereIn('id', $rows->pluck('centre_id'))->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn (Cohort $c) => $this->summary($c) + [
            'qualification' => $quals->get($c->qualification_id), 'centre' => $centreNames->get($c->centre_id),
            // Problems found by the daily check while open.
            'at_risk' => $c->status === 'open' && ($c->watch_fails ?? []) !== [],
        ])->values()->all()]);
    }

    public function show(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');

        return response()->json(['data' => $this->detail($this->find($request, $cohort, 'cohorts.view'))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = app(CohortService::class)->save($request->all(), null, $this->centres($request, 'cohorts.manage'));

        return response()->json(['data' => $this->detail($c)], 201);
    }

    public function update(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = app(CohortService::class)->save($request->all(), $this->find($request, $cohort, 'cohorts.manage'), $this->centres($request, 'cohorts.manage'));

        return response()->json(['data' => $this->detail($c)]);
    }

    public function staff(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = $this->find($request, $cohort, 'cohorts.manage');
        app(CohortService::class)->setStaff($c, $request->input('staff'));

        return response()->json(['data' => $this->detail($c)]);
    }

    public function placements(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = $this->find($request, $cohort, 'cohorts.manage');
        app(CohortService::class)->setPlacements($c, $request->input('placements'));

        return response()->json(['data' => $this->detail($c)]);
    }

    public function readiness(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');

        return response()->json(['data' => app(Readiness::class)->check($this->find($request, $cohort, 'cohorts.view'))]);
    }

    public function open(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = $this->find($request, $cohort, 'cohorts.manage');
        /** @var User $user */
        $user = $request->user();
        app(CohortService::class)->open($c, $user);

        return response()->json(['data' => $this->detail($c->refresh())]);
    }

    public function finish(Request $request, string $cohort, string $outcome): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = $this->find($request, $cohort, 'cohorts.manage');
        app(CohortService::class)->close($c, $outcome === 'close' ? 'closed' : 'cancelled');

        return response()->json(['data' => $this->detail($c->refresh())]);
    }

    /** People who could fill each role (with whether they may) and approved workplaces with free places. */
    public function options(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = $this->find($request, $cohort, 'cohorts.manage');
        $intake = CarbonImmutable::parse($c->intake_on);
        $credentials = app(CredentialService::class);
        $holders = StaffCredential::query()->whereIn('role', CohortStaff::ROLES)->get()->groupBy('role');
        $users = User::query()->whereIn('id', $holders->flatten()->pluck('user_id')->unique())->get()->keyBy('id');
        $people = [];
        foreach (CohortStaff::ROLES as $role) {
            $people[$role] = ($holders->get($role) ?? collect())->pluck('user_id')->unique()->map(function (string $id) use ($users, $credentials, $role, $c, $intake): ?array {
                $u = $users->get($id);
                if ($u === null) {
                    return null;
                }
                $e = $credentials->eligibility($id, $role, $c->qualification_id, $intake);

                return ['user_id' => $id, 'name' => $u->fullName(), 'ok' => $e['ok'], 'reason' => $e['reason']];
            })->filter()->sortByDesc('ok')->values()->all();
        }

        $sites = EmployerSite::query()->where('status', 'active')->get()->filter(fn (EmployerSite $s) => in_array($c->qualification_id, $s->approved_qualification_ids ?? [], true));
        $employers = Employer::query()->whereIn('id', $sites->pluck('employer_id'))->pluck('legal_name', 'id');
        $mentors = Mentor::query()->where('active', true)->whereIn('employer_id', $sites->pluck('employer_id'))->get();
        $taken = Placement::query()->join('cohorts as k', 'k.id', '=', 'cohort_placements.cohort_id')->where('k.status', 'open')->where('k.id', '!=', $c->id)
            ->whereDate('k.intake_on', '<=', $c->planned_end_on)->whereDate('k.planned_end_on', '>=', $c->intake_on)
            ->groupBy('cohort_placements.site_id')->selectRaw('cohort_placements.site_id, sum(planned_learners) as n')->pluck('n', 'site_id');

        return response()->json(['data' => [
            'people' => $people,
            'sites' => $sites->map(function (EmployerSite $s) use ($employers, $mentors, $taken, $c): array {
                $at = $mentors->filter(fn (Mentor $m) => in_array($s->id, $m->site_ids, true));

                return ['id' => $s->id, 'name' => $s->name, 'employer_id' => $s->employer_id, 'employer' => $employers->get($s->employer_id),
                    'approval_state' => $s->approvalState(), 'approved' => $s->approvedFor($c->qualification_id),
                    'free' => max(0, $at->count() * $s->ratioFor($c->qualification_id) - (int) $taken->get($s->id, 0)),
                    'mentors' => $at->map(fn (Mentor $m) => ['id' => $m->id, 'name' => $m->name])->values()->all()];
            })->values()->all(),
        ]]);
    }

    /** @return list<string>|null */
    private function centres(Request $request, string $permission): ?array
    {
        /** @var User $user */
        $user = $request->user();

        return app(AccessService::class)->centresFor($user, $permission);
    }

    private function find(Request $request, string $id, string $permission): Cohort
    {
        $cohort = Cohort::query()->whereKey($id)->firstOrFail();
        $centres = $this->centres($request, $permission);
        abort_if($centres !== null && ! in_array($cohort->centre_id, $centres, true), 404);

        return $cohort;
    }

    /** @return array<string, mixed> */
    private function summary(Cohort $c): array
    {
        return $c->only(['id', 'name', 'qualification_id', 'centre_id', 'mode', 'capacity', 'status', 'notes']) + [
            'intake_on' => $c->intake_on->toDateString(), 'planned_end_on' => $c->planned_end_on->toDateString(),
            'opened_at' => $c->opened_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Cohort $c): array
    {
        $staff = CohortStaff::query()->where('cohort_id', $c->id)->get();
        $names = User::query()->whereIn('id', $staff->pluck('user_id'))->get()->mapWithKeys(fn (User $u) => [$u->id => $u->fullName()]);
        $placements = Placement::query()->where('cohort_id', $c->id)->get();
        $sites = EmployerSite::query()->whereIn('id', $placements->pluck('site_id'))->pluck('name', 'id');
        $employers = Employer::query()->whereIn('id', $placements->pluck('employer_id'))->pluck('legal_name', 'id');
        $mentors = Mentor::query()->whereIn('id', $placements->pluck('mentor_id')->filter())->pluck('name', 'id');
        $q = Qualification::query()->whereKey($c->qualification_id)->first();

        return $this->summary($c) + [
            'qualification' => $q?->summary(),
            'centre' => Centre::query()->whereKey($c->centre_id)->value('name'),
            'staff' => $staff->map(fn (CohortStaff $s) => ['user_id' => $s->user_id, 'role' => $s->role, 'name' => $names->get($s->user_id)])->values()->all(),
            'placements' => $placements->map(fn (Placement $p) => $p->only(['site_id', 'employer_id', 'mentor_id', 'planned_learners']) + [
                'site' => $sites->get($p->site_id), 'employer' => $employers->get($p->employer_id), 'mentor' => $p->mentor_id ? $mentors->get($p->mentor_id) : null,
            ])->values()->all(),
            'readiness' => app(Readiness::class)->check($c),
            'opened_with' => $c->readiness,
        ];
    }
}
