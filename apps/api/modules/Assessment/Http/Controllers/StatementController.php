<?php

declare(strict_types=1);

namespace Modules\Assessment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Assessment\Services\StatementService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

/** Readiness, issuing and withdrawing Statements of Results; the PDF; public verification. */
final class StatementController
{
    public function readiness(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = $this->cohort($request, $cohort, 'cohorts.view');
        $enrolments = Enrolment::query()->where('cohort_id', $c->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $people = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id'));
        $sors = StatementOfResults::query()->whereIn('enrolment_id', $enrolments->pluck('id'))->orderByDesc('issued_at')->get()->groupBy('enrolment_id');
        $svc = app(StatementService::class);

        return response()->json(['data' => $enrolments->map(function (Enrolment $e) use ($c, $people, $sors, $svc) {
            $r = $svc->readiness($e, $c);

            return ['enrolment_id' => $e->id, 'name' => $people->get($e->user_id)?->fullName(), 'ready' => $r['ready'], 'blockers' => $r['blockers'],
                'statements' => ($sors->get($e->id) ?? collect())->map(fn (StatementOfResults $s) => StatementService::present($s))->values()->all()];
        })->sortBy('name')->values()->all()]);
    }

    public function issue(Request $request, string $enrolment): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $e = Enrolment::query()->whereKey($enrolment)->firstOrFail();
        $this->cohort($request, $e->cohort_id, 'cohorts.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => StatementService::present(app(StatementService::class)->issue($e, $me, $request->all()))], 201);
    }

    public function withdraw(Request $request, string $statement): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $s = StatementOfResults::query()->whereKey($statement)->firstOrFail();
        $this->cohort($request, $s->cohort_id, 'cohorts.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => StatementService::present(app(StatementService::class)->withdraw($s, $me, $request->all()))]);
    }

    public function pdf(Request $request, string $statement): Response
    {
        $s = StatementOfResults::query()->whereKey($statement)->firstOrFail();
        /** @var User $me */
        $me = $request->user();
        if ($s->user_id !== $me->id) {
            Gate::authorize('cohorts.view');
            $this->cohort($request, $s->cohort_id, 'cohorts.view');
        }

        return response(app(StatementService::class)->pdf($s), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$s->number.'.pdf"']);
    }

    /** The learner's own statements. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => StatementOfResults::query()->where('user_id', $me->id)->orderByDesc('issued_at')->get()
            ->map(fn (StatementOfResults $s) => StatementService::present($s))->values()->all()]);
    }

    public function verify(string $code): JsonResponse
    {
        return response()->json(['data' => app(StatementService::class)->verification($code)]);
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
}
