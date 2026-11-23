<?php

declare(strict_types=1);

namespace Modules\Assessment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Assessment\Models\EisaCalendar;
use Modules\Assessment\Models\EisaRegistration;
use Modules\Assessment\Services\EisaPack;
use Modules\Assessment\Services\EisaService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Context\TenantContext;

/** The EISA calendar, learners' registrations, the learner's declaration, and packs. */
final class EisaController
{
    public function dates(Request $request): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $data = $request->validate(['qualification_id' => ['required', 'ulid']]);

        return response()->json(['data' => app(EisaService::class)->dates((string) $data['qualification_id'], true)]);
    }

    public function addDate(Request $request): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        /** @var User $me */
        $me = $request->user();
        $d = app(EisaService::class)->addDate($me, $request->all());

        return response()->json(['data' => ['id' => $d->id]], 201);
    }

    public function cohort(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = $this->cohortFor($request, $cohort, 'cohorts.view');
        $data = app(EisaService::class)->cohort($c);
        $people = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', array_column($data['learners'], 'user_id'))->get()->keyBy('id'));
        $data['learners'] = collect($data['learners'])->map(fn (array $l) => $l + ['name' => $people->get($l['user_id'])?->fullName()])->sortBy('name')->values()->all();

        return response()->json(['data' => $data + ['flc' => EisaService::FLC, 'flc_evidence' => EisaService::FLC_EVIDENCE, 'qualification_id' => $c->qualification_id]]);
    }

    public function save(Request $request, string $enrolment): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $e = Enrolment::query()->whereKey($enrolment)->firstOrFail();
        $this->cohortFor($request, $e->cohort_id, 'cohorts.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => EisaService::present(app(EisaService::class)->save($e, $me, $request->all()))]);
    }

    public function register(Request $request, string $registration): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $r = EisaRegistration::query()->whereKey($registration)->firstOrFail();
        $this->cohortFor($request, $r->cohort_id, 'cohorts.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => EisaService::present(app(EisaService::class)->register($r, $me))]);
    }

    public function withdraw(Request $request, string $registration): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $r = EisaRegistration::query()->whereKey($registration)->firstOrFail();
        $this->cohortFor($request, $r->cohort_id, 'cohorts.manage');

        return response()->json(['data' => EisaService::present(app(EisaService::class)->withdraw($r, $request->all()))]);
    }

    public function pack(Request $request, string $registration): Response
    {
        Gate::authorize('cohorts.manage');
        $r = EisaRegistration::query()->whereKey($registration)->where('status', 'registered')->firstOrFail();
        $this->cohortFor($request, $r->cohort_id, 'cohorts.manage');

        return $this->zip(app(EisaPack::class)->zip([$r]), 'eisa-pack-'.$r->eisa_on->format('Ymd').'.zip');
    }

    public function cohortPack(Request $request, string $cohort): Response
    {
        Gate::authorize('cohorts.manage');
        $c = $this->cohortFor($request, $cohort, 'cohorts.manage');
        /** @var list<EisaRegistration> $regs */
        $regs = EisaRegistration::query()->where('cohort_id', $c->id)->where('status', 'registered')->get()->values()->all();

        return $this->zip(app(EisaPack::class)->zip($regs), 'eisa-packs-'.now()->format('Ymd').'.zip');
    }

    /** The learner's own registrations and the declaration to sign. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => EisaRegistration::query()->where('user_id', $me->id)->where('status', '!=', 'withdrawn')->orderBy('eisa_on')->get()
            ->map(fn (EisaRegistration $r) => EisaService::present($r))->values()->all(),
            'declaration' => EisaService::DECLARATION, 'version' => EisaService::DECLARATION_VERSION, 'name' => $me->fullName()]);
    }

    public function declare(Request $request, string $registration): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $r = EisaRegistration::query()->whereKey($registration)->where('user_id', $me->id)->firstOrFail();

        return response()->json(['data' => EisaService::present(app(EisaService::class)->declare($r, $me, $request->all()))]);
    }

    // ── Platform: the EISA calendar from the quality partners' plans ──────────────────────

    public function calendar(Request $request): JsonResponse
    {
        Gate::authorize('platform.catalogue.manage');
        $rows = EisaCalendar::query()->orderBy('eisa_on')->get();
        $titles = Qualification::query()->whereIn('id', $rows->pluck('qualification_id'))->pluck('title', 'id');

        return response()->json(['data' => $rows->map(fn (EisaCalendar $c) => $c->only(['id', 'qualification_id', 'quality_partner', 'source'])
            + ['eisa_on' => $c->eisa_on->toDateString(), 'closes_on' => $c->closes_on->toDateString(), 'qualification' => $titles->get($c->qualification_id)])->values()->all()]);
    }

    public function addCalendar(Request $request): JsonResponse
    {
        Gate::authorize('platform.catalogue.manage');
        $data = $request->validate([
            'qualification_id' => ['required', 'ulid', 'exists:qualifications,id'],
            'eisa_on' => ['required', 'date', 'after:today'],
            'closes_on' => ['required', 'date', 'before:eisa_on'],
            'quality_partner' => ['nullable', 'string', 'max:60'],
            'source' => ['required', 'string', 'max:300'],
        ]);
        $c = EisaCalendar::query()->updateOrCreate(['qualification_id' => $data['qualification_id'], 'eisa_on' => $data['eisa_on']], $data);

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    private function zip(string $bytes, string $name): Response
    {
        return response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store']);
    }

    private function cohortFor(Request $request, string $id, string $permission): Cohort
    {
        $c = Cohort::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($c->centre_id, $centres, true), 404);

        return $c;
    }
}
