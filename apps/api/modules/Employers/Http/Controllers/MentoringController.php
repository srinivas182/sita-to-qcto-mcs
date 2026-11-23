<?php

declare(strict_types=1);

namespace Modules\Employers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Models\User;

/**
 * "My learners" for a workplace mentor signed in on the app: where they mentor and the
 * cohorts planned at those workplaces. Learner names and logbooks arrive with enrolment
 * (Sprint 4) and logbooks (Sprint 7).
 */
final class MentoringController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $mentors = Mentor::query()->where('user_id', $me->id)->where('active', true)->get();
        $siteIds = $mentors->pluck('site_ids')->flatten()->unique()->values();
        $sites = EmployerSite::query()->whereIn('id', $siteIds)->get()->keyBy('id');
        $employers = Employer::query()->whereIn('id', $mentors->pluck('employer_id'))->pluck('legal_name', 'id');
        $cohorts = DB::table('cohort_placements as p')->join('cohorts as c', 'c.id', '=', 'p.cohort_id')->join('qualifications as q', 'q.id', '=', 'c.qualification_id')
            ->whereIn('p.site_id', $siteIds)->whereIn('c.status', ['draft', 'open'])->whereDate('c.planned_end_on', '>=', now())
            ->where(fn ($w) => $w->whereNull('p.mentor_id')->orWhereIn('p.mentor_id', $mentors->pluck('id')))
            ->orderBy('c.intake_on')->get(['p.site_id', 'p.planned_learners', 'c.name', 'c.status', 'c.intake_on', 'c.planned_end_on', 'q.title']);

        return response()->json(['data' => [
            'is_mentor' => $mentors->isNotEmpty(),
            'workplaces' => $siteIds->map(fn ($id) => $sites->get($id))->filter()->map(fn (EmployerSite $s) => [
                'id' => $s->id, 'name' => $s->name, 'employer' => $employers->get($s->employer_id), 'city' => $s->city, 'approval_state' => $s->approvalState(),
                'cohorts' => $cohorts->where('site_id', $s->id)->map(fn ($c) => [
                    'name' => $c->name, 'qualification' => $c->title, 'status' => $c->status, 'learners' => (int) $c->planned_learners,
                    'intake_on' => (string) $c->intake_on, 'planned_end_on' => (string) $c->planned_end_on,
                ])->values()->all(),
            ])->values()->all(),
        ]]);
    }
}
