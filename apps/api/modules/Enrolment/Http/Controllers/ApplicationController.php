<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Services\ApplicationService;
use Modules\Identity\Models\User;

/** The provider's applications board. */
final class ApplicationController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('applications.view');
        $data = $request->validate(['intake_id' => ['required', 'ulid'], 'status' => ['nullable', 'string', 'max:12']]);
        $intake = $this->intake($request, (string) $data['intake_id'], 'applications.view');
        $query = Application::query()->where('intake_id', $intake->id)->orderBy('submitted_at');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $rows = $query->limit(2000)->get();
        $people = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        $taken = Application::query()->where('intake_id', $intake->id)->whereIn('status', ['offered', 'accepted', 'enrolled'])->count();

        return response()->json([
            'data' => $rows->map(function (Application $a) use ($people): array {
                $u = $people->get($a->user_id);

                return $a->only(['id', 'reference', 'status', 'answers', 'reason', 'waitlist_position', 'source']) + [
                    'submitted_at' => $a->submitted_at->toIso8601String(),
                    'applicant' => $u === null ? null : [
                        'id' => $u->id, 'name' => $u->fullName(), 'phone' => $u->phone_e164, 'email' => $u->email,
                        'id_number' => $u->maskedIdNumber(), 'id_type' => $u->id_type?->value,
                        'age' => $u->dob !== null ? (int) floor(CarbonImmutable::parse($u->dob)->diffInYears(CarbonImmutable::today())) : null,
                    ],
                ];
            })->values()->all(),
            'places' => ['total' => $intake->places, 'taken' => $taken],
        ]);
    }

    public function decide(Request $request, string $application, string $action): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $a = Application::query()->whereKey($application)->firstOrFail();
        $this->intake($request, $a->intake_id, 'applications.manage');
        /** @var User $user */
        $user = $request->user();
        app(ApplicationService::class)->decide($a, $action, $data['reason'] ?? null, $user);

        return response()->json(['data' => $a->only(['id', 'status', 'reason', 'waitlist_position'])]);
    }

    private function intake(Request $request, string $id, string $permission): Intake
    {
        $intake = Intake::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_if($centres !== null && ! in_array(Cohort::query()->whereKey($intake->cohort_id)->value('centre_id'), $centres, true), 404);

        return $intake;
    }
}
