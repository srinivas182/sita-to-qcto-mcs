<?php

declare(strict_types=1);

namespace Modules\Funding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Funding\Models\BankAccount;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\StipendLine;
use Modules\Funding\Models\StipendRun;
use Modules\Funding\Services\GrantService;
use Modules\Funding\Services\StipendService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

/** Stipend rules, monthly runs, bank files and learners' bank details. */
final class StipendController
{
    public function rule(Request $request, string $agreement): JsonResponse
    {
        $a = $this->agreement($request, $agreement, 'funding.manage');

        return response()->json(['data' => GrantService::present(app(StipendService::class)->saveRule($a, $request->all())) + self::rules($a)]);
    }

    public function runs(Request $request, string $agreement): JsonResponse
    {
        $a = $this->agreement($request, $agreement, 'funding.view');

        return response()->json(['data' => StipendRun::query()->where('agreement_id', $a->id)->orderByDesc('month')->get()
            ->map(fn (StipendRun $r) => StipendService::present($r))->all(), 'rule' => self::rules($a)]);
    }

    public function propose(Request $request, string $agreement): JsonResponse
    {
        $a = $this->agreement($request, $agreement, 'funding.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => StipendService::present(app(StipendService::class)->propose($a, (string) $request->input('month'), $me))], 201);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $r = StipendRun::query()->whereKey($run)->firstOrFail();
        $this->agreement($request, $r->agreement_id, 'funding.view');
        $lines = StipendLine::query()->where('run_id', $r->id)->get();
        $people = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', $lines->pluck('user_id'))->get()->keyBy('id'));
        $banks = BankAccount::query()->whereIn('user_id', $lines->pluck('user_id'))->get()->keyBy('user_id');

        return response()->json(['data' => StipendService::present($r) + ['lines' => $lines->map(fn (StipendLine $l) => [
            'id' => $l->id, 'enrolment_id' => $l->enrolment_id, 'name' => $people->get($l->user_id)?->fullName(), 'attendance_pct' => $l->attendance_pct,
            'proposed_cents' => $l->proposed_cents, 'amount_cents' => $l->amount_cents, 'status' => $l->status, 'reason' => $l->reason,
            'override_reason' => $l->override_reason, 'bank' => $banks->get($l->user_id)?->present()])->sortBy('name')->values()->all()]]);
    }

    public function line(Request $request, string $line): JsonResponse
    {
        $l = StipendLine::query()->whereKey($line)->firstOrFail();
        $r = StipendRun::query()->whereKey($l->run_id)->firstOrFail();
        $this->agreement($request, $r->agreement_id, 'funding.manage');
        /** @var User $me */
        $me = $request->user();
        $l = app(StipendService::class)->override($l, $me, $request->all());

        return response()->json(['data' => ['id' => $l->id, 'amount_cents' => $l->amount_cents, 'status' => $l->status, 'override_reason' => $l->override_reason]]);
    }

    public function confirm(Request $request, string $run): JsonResponse
    {
        $r = StipendRun::query()->whereKey($run)->firstOrFail();
        $this->agreement($request, $r->agreement_id, 'funding.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => StipendService::present(app(StipendService::class)->confirm($r, $me))]);
    }

    public function bankFile(Request $request, string $run): Response
    {
        $r = StipendRun::query()->whereKey($run)->firstOrFail();
        $this->agreement($request, $r->agreement_id, 'funding.manage');
        /** @var User $me */
        $me = $request->user();
        $csv = app(StipendService::class)->bankFile($r, $me);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="stipends-'.$r->month->format('Y-m').'.csv"']);
    }

    public function paid(Request $request, string $run): JsonResponse
    {
        $r = StipendRun::query()->whereKey($run)->firstOrFail();
        $this->agreement($request, $r->agreement_id, 'funding.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => StipendService::present(app(StipendService::class)->paid($r, $me, $request->all()))]);
    }

    public function bank(Request $request, string $enrolment): JsonResponse
    {
        $e = $this->enrolment($request, $enrolment, 'funding.view');

        return response()->json(['data' => BankAccount::query()->where('user_id', $e->user_id)->first()?->present(), 'banks' => self::banks()]);
    }

    public function saveBank(Request $request, string $enrolment): JsonResponse
    {
        $e = $this->enrolment($request, $enrolment, 'funding.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => app(StipendService::class)->saveBank($e->user_id, $me, $request->all())->present()]);
    }

    public function myBank(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => BankAccount::query()->where('user_id', $me->id)->first()?->present(), 'banks' => self::banks()]);
    }

    public function saveMyBank(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        abort_unless(Enrolment::query()->where('user_id', $me->id)->exists(), 403);

        return response()->json(['data' => app(StipendService::class)->saveBank($me->id, $me, $request->all())->present()]);
    }

    public function mine(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => app(StipendService::class)->mine($me), 'bank' => BankAccount::query()->where('user_id', $me->id)->first()?->present()]);
    }

    /** @return list<array{key: string, name: string, branch_code: string}> */
    private static function banks(): array
    {
        return array_map(fn (string $k) => ['key' => $k, 'name' => BankAccount::BANKS[$k][0], 'branch_code' => BankAccount::BANKS[$k][1]], array_keys(BankAccount::BANKS));
    }

    /** @return array<string, mixed> */
    private static function rules(GrantAgreement $a): array
    {
        return ['stipend_cents' => $a->stipend_cents, 'full_pct' => $a->stipend_full_pct, 'hold_pct' => $a->stipend_hold_pct, 'prorata' => $a->stipend_prorata];
    }

    private function agreement(Request $request, string $id, string $permission): GrantAgreement
    {
        Gate::authorize($permission);
        $a = GrantAgreement::query()->whereKey($id)->firstOrFail();
        $this->cohort($request, $a->cohort_id, $permission);

        return $a;
    }

    private function enrolment(Request $request, string $id, string $permission): Enrolment
    {
        Gate::authorize($permission);
        $e = Enrolment::query()->whereKey($id)->firstOrFail();
        $this->cohort($request, $e->cohort_id, $permission);

        return $e;
    }

    private function cohort(Request $request, string $id, string $permission): void
    {
        $c = Cohort::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($c->centre_id, $centres, true), 404);
    }
}
