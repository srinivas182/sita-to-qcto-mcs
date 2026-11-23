<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\ConsentText;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Models\Offer;
use Modules\Enrolment\Services\EnrolmentService;
use Modules\Enrolment\Services\OfferService;
use Modules\Identity\Models\User;

/** Provider side: making and withdrawing offers, enrolments of a cohort, and the consent wording. */
final class OfferController
{
    public function store(Request $request, string $application): JsonResponse
    {
        Gate::authorize('applications.manage');
        $a = $this->application($request, $application);
        /** @var User $by */
        $by = $request->user();
        $made = $this->offers()->make($a, $request->all(), $by);

        return response()->json(['data' => $this->offers()->present($made['offer']) + ['link' => $made['link']]], 201);
    }

    public function withdraw(Request $request, string $offer): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $o = Offer::query()->whereKey($offer)->firstOrFail();
        $this->application($request, $o->application_id);
        $this->offers()->withdraw($o, $data['reason']);

        return response()->json(['data' => $this->offers()->present($o->refresh())]);
    }

    public function enrolments(Request $request): JsonResponse
    {
        Gate::authorize('applications.view');
        $data = $request->validate(['cohort_id' => ['required', 'ulid']]);
        $cohort = Cohort::query()->whereKey($data['cohort_id'])->firstOrFail();
        $this->centre($request, $cohort->centre_id, 'applications.view');
        $rows = Enrolment::query()->where('cohort_id', $cohort->id)->orderBy('enrolled_on')->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn (Enrolment $e) => $e->only(['id', 'status', 'funding', 'reason', 'application_id', 'agreement_id']) + [
            'enrolled_on' => $e->enrolled_on->toDateString(), 'ended_on' => $e->ended_on?->toDateString(),
            'learner' => ['id' => $e->user_id, 'name' => $users->get($e->user_id)?->fullName()],
        ])->values()->all()]);
    }

    public function end(Request $request, string $enrolment): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['suspended', 'withdrawn'])], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $e = Enrolment::query()->whereKey($enrolment)->firstOrFail();
        $this->centre($request, (string) Cohort::query()->whereKey($e->cohort_id)->value('centre_id'), 'applications.manage');
        $e = app(EnrolmentService::class)->end($e, $data['status'], $data['reason']);

        return response()->json(['data' => ['id' => $e->id, 'status' => $e->status]]);
    }

    public function consentText(): JsonResponse
    {
        Gate::authorize('applications.view');
        $t = ConsentText::current('enrolment');

        return response()->json(['data' => ['version' => $t->version, 'text' => $t->text, 'default' => ConsentText::DEFAULT_ENROLMENT, 'updated_at' => $t->updated_at?->toIso8601String()]]);
    }

    /** Saving changed wording makes a new version; consents already given keep the version they agreed to. */
    public function updateConsentText(Request $request): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate(['text' => ['required', 'string', 'min:100', 'max:5000']]);
        $current = ConsentText::current('enrolment');
        /** @var User $by */
        $by = $request->user();
        if (trim($data['text']) !== trim($current->text)) {
            $current = ConsentText::query()->create(['purpose' => 'enrolment', 'version' => $current->version + 1, 'text' => trim($data['text']), 'created_by' => $by->id]);
        }

        return response()->json(['data' => ['version' => $current->version, 'text' => $current->text, 'default' => ConsentText::DEFAULT_ENROLMENT, 'updated_at' => $current->updated_at?->toIso8601String()]]);
    }

    private function application(Request $request, string $id): Application
    {
        $a = Application::query()->whereKey($id)->firstOrFail();
        $cohort = Intake::query()->whereKey($a->intake_id)->value('cohort_id');
        $this->centre($request, (string) Cohort::query()->whereKey($cohort)->value('centre_id'), 'applications.manage');

        return $a;
    }

    private function centre(Request $request, string $centreId, string $permission): void
    {
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($centreId, $centres, true), 404);
    }

    /** Resolved per request: controllers are reused, the tenant context is not. */
    private function offers(): OfferService
    {
        return app(OfferService::class);
    }
}
