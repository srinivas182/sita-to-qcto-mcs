<?php

declare(strict_types=1);

namespace Modules\Assessment\Http\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Assessment\Models\Appeal;
use Modules\Assessment\Models\Instrument;
use Modules\Assessment\Models\Report;
use Modules\Assessment\Models\Result;
use Modules\Assessment\Models\Sample;
use Modules\Assessment\Services\AssessmentService;
use Modules\Assessment\Services\ModerationService;
use Modules\Cohorts\Models\Cohort;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;

/** The moderator's queue, decisions and reports; learners' appeals. */
final class ModerationController
{
    /** Open samples and appeals at the moderator's centres, and recent decisions. */
    public function queue(Request $request): JsonResponse
    {
        Gate::authorize('assessment.moderate');
        $cohorts = $this->cohortIds($request, 'assessment.moderate');
        $samples = Sample::query()->whereIn('cohort_id', $cohorts)->where(fn ($q) => $q->where('status', 'open')->orWhere('decided_at', '>=', now()->subDays(30)))
            ->orderBy('status', 'desc')->orderBy('created_at')->limit(300)->get();
        $appeals = Appeal::query()->whereIn('cohort_id', $cohorts)->where(fn ($q) => $q->where('status', 'open')->orWhere('decided_at', '>=', now()->subDays(30)))->orderBy('decide_by')->get();
        $results = Result::query()->whereIn('id', $samples->pluck('result_id')->merge($appeals->pluck('result_id')))->get()->keyBy('id');
        $names = $this->names($results->pluck('user_id')->merge($results->pluck('assessor_id')));
        $modules = QualificationModule::query()->whereIn('id', $results->pluck('module_id'))->pluck('code', 'id');
        $titles = Instrument::query()->whereIn('id', $results->pluck('instrument_id'))->pluck('title', 'id');
        $cohortNames = Cohort::query()->whereIn('id', $samples->pluck('cohort_id')->merge($appeals->pluck('cohort_id')))->pluck('name', 'id');
        $describe = fn (?Result $r) => $r ? ['learner' => $names->get($r->user_id), 'assessor' => $names->get($r->assessor_id), 'module' => $modules->get($r->module_id),
            'instrument' => $titles->get($r->instrument_id), 'outcome' => $r->outcome, 'attempt' => $r->attempt, 'assessed_on' => $r->assessed_on->toDateString()] : [];

        return response()->json(['data' => [
            'samples' => $samples->map(fn (Sample $s) => ['id' => $s->id, 'result_id' => $s->result_id, 'cohort_id' => $s->cohort_id, 'cohort' => $cohortNames->get($s->cohort_id),
                'module_id' => $s->module_id, 'reason' => $s->reason, 'status' => $s->status, 'decision' => $s->decision] + $describe($results->get($s->result_id)))->values()->all(),
            'appeals' => $appeals->map(fn (Appeal $a) => self::presentAppeal($a) + ['cohort' => $cohortNames->get($a->cohort_id)] + $describe($results->get($a->result_id)))->values()->all(),
        ]]);
    }

    /** A sampled result in full, with the instrument's criteria, for the moderator. */
    public function sample(Request $request, string $sample): JsonResponse
    {
        Gate::authorize('assessment.moderate');
        $s = Sample::query()->whereKey($sample)->firstOrFail();
        abort_unless(in_array($s->cohort_id, $this->cohortIds($request, 'assessment.moderate'), true), 404);
        $r = Result::query()->whereKey($s->result_id)->firstOrFail();
        $i = Instrument::query()->whereKey($r->instrument_id)->firstOrFail();
        $names = $this->names(collect([$r->user_id, $r->assessor_id]));

        return response()->json(['data' => ['id' => $s->id, 'reason' => $s->reason, 'status' => $s->status, 'decision' => $s->decision, 'comment' => $s->comment,
            'result' => AssessmentService::present($r) + ['learner' => $names->get($r->user_id), 'assessor' => $names->get($r->assessor_id)],
            'instrument' => $i->only(['id', 'method', 'title', 'criteria', 'pass_mark', 'instructions'])]]);
    }

    public function decide(Request $request, string $sample): JsonResponse
    {
        Gate::authorize('assessment.moderate');
        $s = Sample::query()->whereKey($sample)->firstOrFail();
        abort_unless(in_array($s->cohort_id, $this->cohortIds($request, 'assessment.moderate'), true), 404);
        /** @var User $me */
        $me = $request->user();
        $s = app(ModerationService::class)->decide($s, $me, $request->all());

        return response()->json(['data' => $s->only(['id', 'status', 'decision', 'comment', 'new_result_id'])]);
    }

    /** Per module: decisions, sample, agreement, policy compliance, and signed reports. */
    public function cohort(Request $request, string $cohort): JsonResponse
    {
        $c = Cohort::query()->whereKey($cohort)->firstOrFail();
        $perms = array_values(array_filter(['cohorts.view', 'assessment.moderate', 'assessment.conduct'], fn (string $p) => Gate::allows($p)));
        abort_if($perms === [], 403);
        abort_unless(collect($perms)->contains(fn (string $p) => in_array($c->id, $this->cohortIds($request, $p), true)), 404);
        $svc = app(ModerationService::class);
        $modules = QualificationModule::query()->where('qualification_id', $c->qualification_id)->orderBy('sort')->get();
        $reports = Report::query()->where('cohort_id', $c->id)->orderByDesc('signed_at')->get()->groupBy('module_id');
        $names = $this->names(Result::query()->whereIn('module_id', $modules->pluck('id'))->distinct()->pluck('assessor_id')
            ->merge($reports->flatten()->pluck('moderator_id')));

        return response()->json(['data' => $modules->map(function (QualificationModule $m) use ($c, $svc, $reports, $names) {
            $sum = $svc->summary($c->id, $m->id);
            $sum['assessors'] = array_map(fn (array $a) => $a + ['name' => $names->get($a['assessor_id'])], $sum['assessors']);

            return ['module_id' => $m->id, 'code' => $m->code, 'title' => $m->title] + $sum + ['reports' => ($reports->get($m->id) ?? collect())
                ->map(fn (Report $r) => ['id' => $r->id, 'moderator' => $names->get($r->moderator_id), 'signed_at' => $r->signed_at->toIso8601String()])->values()->all()];
        })->filter(fn (array $m) => $m['decisions'] > 0 || $m['reports'] !== [])->values()->all()]);
    }

    public function report(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('assessment.moderate');
        abort_unless(in_array($cohort, $this->cohortIds($request, 'assessment.moderate'), true), 404);
        /** @var User $me */
        $me = $request->user();
        $r = app(ModerationService::class)->report(Cohort::query()->whereKey($cohort)->firstOrFail(), $me, $request->all());

        return response()->json(['data' => ['id' => $r->id, 'signed_at' => $r->signed_at->toIso8601String(), 'hash' => $r->hash]], 201);
    }

    public function pdf(Request $request, string $report): Response
    {
        $r = Report::query()->whereKey($report)->firstOrFail();
        $perms = array_values(array_filter(['cohorts.view', 'assessment.moderate'], fn (string $p) => Gate::allows($p)));
        abort_if($perms === [], 403);
        abort_unless(collect($perms)->contains(fn (string $p) => in_array($r->cohort_id, $this->cohortIds($request, $p), true)), 404);
        $c = Cohort::query()->whereKey($r->cohort_id)->firstOrFail();
        $m = QualificationModule::query()->whereKey($r->module_id)->firstOrFail();
        /** @var list<array{result_id: string}> $rows */
        $rows = $r->summary['samples'] ?? [];
        $results = Result::query()->whereIn('id', array_column($rows, 'result_id'))->get()->keyBy('id');
        $names = $this->names($results->pluck('user_id')->merge($results->pluck('assessor_id'))->push($r->moderator_id));
        $tenant = app(TenantContext::class)->tenant();
        $html = view('assessment::moderation-report', ['r' => $r, 'cohort' => $c, 'module' => $m, 'results' => $results, 'names' => $names,
            'provider' => $tenant?->legal_name ?: $tenant?->trading_name])->render();

        return response(self::pdfFrom($html), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="moderation-'.$m->code.'-'.$r->signed_at->format('Ymd').'.pdf"']);
    }

    public static function pdfFrom(string $html, string $orientation = 'portrait'): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }

    public function appeal(Request $request, string $result): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $r = Result::query()->whereKey($result)->where('user_id', $me->id)->firstOrFail();

        return response()->json(['data' => self::presentAppeal(app(ModerationService::class)->appeal($r, $me, $request->all()))], 201);
    }

    public function decideAppeal(Request $request, string $appeal): JsonResponse
    {
        Gate::authorize('assessment.moderate');
        $a = Appeal::query()->whereKey($appeal)->firstOrFail();
        abort_unless(in_array($a->cohort_id, $this->cohortIds($request, 'assessment.moderate'), true), 404);
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => self::presentAppeal(app(ModerationService::class)->decideAppeal($a, $me, $request->all()))]);
    }

    /** @return array<string, mixed> */
    public static function presentAppeal(Appeal $a): array
    {
        return ['id' => $a->id, 'result_id' => $a->result_id, 'reason' => $a->reason, 'lodged_at' => $a->lodged_at->toIso8601String(), 'decide_by' => $a->decide_by->toDateString(),
            'status' => $a->status, 'decision_note' => $a->decision_note, 'decided_at' => $a->decided_at?->toIso8601String(),
            'overdue' => $a->status === 'open' && $a->decide_by->isPast()];
    }

    /** @return list<string> */
    private function cohortIds(Request $request, string $permission): array
    {
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);

        return array_values(array_map('strval', Cohort::query()->when($centres !== null, fn ($q) => $q->whereIn('centre_id', $centres))->pluck('id')->all()));
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return Collection<string, string>
     */
    private function names(Collection $ids): Collection
    {
        return app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', $ids->filter()->unique()->values())->get()->mapWithKeys(fn (User $u) => [$u->id => $u->fullName()]));
    }
}
