<?php

declare(strict_types=1);

namespace Modules\Logbook\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Cohorts\Models\Cohort;
use Modules\Employers\Models\EmployerSite;
use Modules\Identity\Models\User;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Models\LogbookRule;
use Modules\Logbook\Models\LogbookSignoff;
use Modules\Logbook\Models\LogbookWeek;
use Modules\Logbook\Services\LogbookService;
use Modules\Logbook\Services\SignOffService;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;

/** The mentor's side: the learners placed with them, weeks to sign, and signing or sending back. */
final class MentorLogbookController
{
    public function weeks(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $mentorIds = $this->signOff()->mentorsOf($me)->pluck('id');
        $placements = LearnerPlacement::query()->whereIn('mentor_id', $mentorIds)->get()->keyBy('id');
        $people = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', $placements->pluck('user_id'))->get()->keyBy('id'));
        $sites = EmployerSite::query()->whereIn('id', $placements->pluck('site_id'))->pluck('name', 'id');
        $weeks = LogbookWeek::query()->whereIn('learner_placement_id', $placements->keys())
            ->where(fn ($q) => $q->where('status', '!=', 'signed')->orWhere('signed_at', '>=', now()->subWeeks(6)))
            ->orderByDesc('week_start')->limit(200)->get();
        $totals = LogbookEntry::query()->whereIn('learner_placement_id', $placements->keys())->where('status', '!=', 'corrected')
            ->selectRaw('learner_placement_id, week_start, count(*) as n, sum(minutes) as m')->groupBy('learner_placement_id', 'week_start')->get()
            ->keyBy(fn ($r) => $r->learner_placement_id.'|'.CarbonImmutable::parse((string) $r->week_start)->toDateString());
        $today = CarbonImmutable::now(LogbookService::TZ)->toDateString();
        $signoffs = LogbookSignoff::query()->whereIn('enrolment_id', $placements->pluck('enrolment_id'))->get();

        return response()->json(['data' => [
            'is_mentor' => $mentorIds->isNotEmpty(),
            'statement' => LogbookSignoff::STATEMENT,
            'learners' => $placements->filter(fn (LearnerPlacement $p) => $p->ends_on === null || $p->ends_on->toDateString() >= CarbonImmutable::now()->subMonth()->toDateString())
                ->map(fn (LearnerPlacement $p) => ['placement_id' => $p->id, 'enrolment_id' => $p->enrolment_id, 'name' => $people->get($p->user_id)?->fullName(), 'site' => $sites->get($p->site_id),
                    'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'active' => $p->covers($today),
                    'modules' => $this->modules($p, $signoffs)])->values()->all(),
            'weeks' => $weeks->map(function (LogbookWeek $w) use ($placements, $people, $totals) {
                $t = $totals->get($w->learner_placement_id.'|'.$w->week_start->toDateString());

                return SignOffService::present($w) + ['learner' => $people->get($placements->get($w->learner_placement_id)?->user_id)?->fullName(),
                    'entries' => (int) ($t->n ?? 0), 'minutes' => (int) ($t->m ?? 0)];
            })->filter(fn ($w) => $w['entries'] > 0)->values()->all(),
        ]]);
    }

    public function show(Request $request, string $week): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $w = $this->signOff()->mentorWeek($me, $week);
        $p = LearnerPlacement::query()->whereKey($w->learner_placement_id)->firstOrFail();
        $cohort = Cohort::query()->whereKey($p->cohort_id)->firstOrFail();
        $modules = QualificationModule::query()->where('qualification_id', $cohort->qualification_id)->where('kind', 'WM')->get()->keyBy('id');
        $rules = LogbookRule::query()->whereIn('module_id', $modules->keys())->get()->keyBy('module_id');
        $learner = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereKey($w->user_id)->first());

        return response()->json(['data' => SignOffService::present($w) + [
            'learner' => $learner?->fullName(),
            'entries' => $this->signOff()->entries($w)->map(fn (LogbookEntry $e) => LogbookService::present($e) + [
                'module' => $modules->get($e->module_id)?->code,
                'activity' => collect($rules->get($e->module_id)->activities ?? [])->firstWhere('key', $e->activity_key)['title'] ?? $e->activity_key,
            ])->values()->all(),
        ]]);
    }

    /** Final sign-off of a learner's work experience module, with the authenticity statement. */
    public function final(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => SignOffService::presentSignoff($this->signOff()->final($me, $request->all()))], 201);
    }

    public function sign(Request $request, string $week): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => SignOffService::present($this->signOff()->sign($this->signOff()->mentorWeek($me, $week), $me, $request->all()))]);
    }

    public function return(Request $request, string $week): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => SignOffService::present($this->signOff()->return($this->signOff()->mentorWeek($me, $week), $me, $request->all()))]);
    }

    /**
     * Hours per work experience module for the learner, with the final sign-off if any.
     *
     * @param  Collection<int, LogbookSignoff>  $signoffs
     * @return list<array<string, mixed>>
     */
    private function modules(LearnerPlacement $p, Collection $signoffs): array
    {
        $qualification = Cohort::query()->whereKey($p->cohort_id)->value('qualification_id');

        return array_map(fn (array $h) => $h + ['signoff' => ($s = $signoffs->where('enrolment_id', $p->enrolment_id)->firstWhere('module_id', $h['module_id']))
            ? SignOffService::presentSignoff($s) : null], app(LogbookService::class)->hours($p->user_id, (string) $qualification));
    }

    private function signOff(): SignOffService
    {
        return app(SignOffService::class);
    }
}
