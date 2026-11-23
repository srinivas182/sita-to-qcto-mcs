<?php

declare(strict_types=1);

namespace Modules\Funding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Access\Services\AccessService;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Funding\Models\Funder;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantLearner;
use Modules\Funding\Models\GrantTranche;
use Modules\Funding\Services\FixInbox;
use Modules\Funding\Services\GrantProgress;
use Modules\Funding\Services\GrantService;
use Modules\Funding\Services\LearnerTodo;
use Modules\Funding\Services\Milestones;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

/** Funders, grant agreements per cohort, and tranche progress. */
final class FundingController
{
    public function funders(): JsonResponse
    {
        Gate::authorize('funding.view');

        return response()->json(['data' => Funder::query()->orderBy('name')->get()->map(fn (Funder $f) => $f->present())->all(),
            'kinds' => Funder::KINDS, 'templates' => Funder::TEMPLATES, 'milestones' => Milestones::LIST]);
    }

    public function saveFunder(Request $request, ?string $funder = null): JsonResponse
    {
        Gate::authorize('funding.manage');
        $f = $funder === null ? null : Funder::query()->whereKey($funder)->firstOrFail();

        return response()->json(['data' => app(GrantService::class)->saveFunder($f, $request->all())->present()], $f === null ? 201 : 200);
    }

    public function cohort(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('funding.view');
        $c = $this->cohortFor($request, $cohort, 'funding.view');
        $progress = app(GrantProgress::class);

        return response()->json(['data' => GrantAgreement::query()->where('cohort_id', $c->id)->orderBy('starts_on')->get()
            ->map(function (GrantAgreement $a) use ($progress): array {
                $p = $progress->of($a);

                return $p['agreement'] + ['tranches' => $p['tranches']];
            })->all()]);
    }

    /** Learners in the cohort who can be put on an agreement, and which agreement already funds them. */
    public function candidates(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('funding.manage');
        $c = $this->cohortFor($request, $cohort, 'funding.manage');
        $enrolments = Enrolment::query()->where('cohort_id', $c->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $people = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id'));
        $funded = GrantLearner::query()->whereIn('enrolment_id', $enrolments->pluck('id'))->where('status', 'active')->get(['enrolment_id', 'agreement_id'])
            ->groupBy('enrolment_id');

        return response()->json(['data' => $enrolments->map(fn (Enrolment $e) => ['enrolment_id' => $e->id, 'name' => $people->get($e->user_id)?->fullName(),
            'funding' => $e->funding, 'agreement_ids' => ($funded->get($e->id) ?? collect())->pluck('agreement_id')->all()])->sortBy('name')->values()->all()]);
    }

    public function create(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('funding.manage');
        $c = $this->cohortFor($request, $cohort, 'funding.manage');
        /** @var User $me */
        $me = $request->user();
        $a = app(GrantService::class)->saveAgreement($c, null, $me, $request->all());

        return response()->json(['data' => GrantService::present($a)], 201);
    }

    public function show(Request $request, string $agreement): JsonResponse
    {
        Gate::authorize('funding.view');
        $a = GrantAgreement::query()->whereKey($agreement)->firstOrFail();
        $this->cohortFor($request, $a->cohort_id, 'funding.view');
        $tranches = GrantTranche::query()->where('agreement_id', $a->id)->orderBy('number')->get();

        return response()->json(['data' => app(GrantProgress::class)->of($a) + ['form' => [
            'enrolment_ids' => GrantLearner::query()->where('agreement_id', $a->id)->where('status', 'active')->pluck('enrolment_id')->all(),
            'disabled_ids' => GrantLearner::query()->where('agreement_id', $a->id)->where('status', 'active')->where('disabled', true)->pluck('enrolment_id')->all(),
            'tranches' => $tranches->map(fn (GrantTranche $t) => ['share_pct' => (float) $t->share_pct, 'due_on' => $t->due_on?->toDateString(),
                'milestones' => $t->milestones, 'evidence' => $t->evidence])->all(),
        ]]]);
    }

    public function update(Request $request, string $agreement): JsonResponse
    {
        Gate::authorize('funding.manage');
        $a = GrantAgreement::query()->whereKey($agreement)->firstOrFail();
        $c = $this->cohortFor($request, $a->cohort_id, 'funding.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => GrantService::present(app(GrantService::class)->saveAgreement($c, $a, $me, $request->all()))]);
    }

    /** Attach the evidence for one item the platform doesn't hold (e.g. the certificate list). */
    public function evidence(Request $request, string $tranche): JsonResponse
    {
        Gate::authorize('funding.manage');
        $t = GrantTranche::query()->whereKey($tranche)->firstOrFail();
        $a = GrantAgreement::query()->whereKey($t->agreement_id)->firstOrFail();
        $this->cohortFor($request, $a->cohort_id, 'funding.manage');
        $data = $request->validate(['index' => ['required', 'integer', 'min:0'], 'file_id' => ['nullable', 'ulid']]);
        $evidence = $t->evidence;
        if (! isset($evidence[$data['index']])) {
            throw ValidationException::withMessages(['index' => 'That evidence item no longer exists.']);
        }
        if (! empty($data['file_id']) && ! File::query()->whereKey($data['file_id'])->where('purpose', 'grant_document')->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['file_id' => 'Upload the file again.']);
        }
        $evidence[$data['index']]['file_id'] = $data['file_id'] ?? null;
        $t->forceFill(['evidence' => $evidence])->save();
        app(AuditLogger::class)->record('grant.evidence', $t, [], ['item' => $evidence[$data['index']]['title'], 'attached' => ! empty($data['file_id'])]);

        return response()->json(['data' => ['evidence' => $evidence]]);
    }

    /** The provider's "To fix" list and funding numbers for the home page. */
    public function inbox(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => app(FixInbox::class)->for($me)]);
    }

    /** The learner's own one-tap fixes. */
    public function todo(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => app(LearnerTodo::class)->for($me)]);
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
