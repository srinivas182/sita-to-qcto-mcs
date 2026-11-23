<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Services\ApplicationService;
use Modules\Identity\Models\User;

/** The signed-in person's own applications (app and web). */
final class MyApplicationsController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Application::query()->where('user_id', $this->me($request)->id)->latest('submitted_at')->get();
        $intakes = Intake::query()->whereIn('id', $rows->pluck('intake_id'))->pluck('title', 'id');

        return response()->json(['data' => $rows->map(fn (Application $a) => $a->only(['id', 'reference', 'status', 'reason', 'waitlist_position']) + [
            'intake' => $intakes->get($a->intake_id), 'intake_id' => $a->intake_id, 'submitted_at' => $a->submitted_at->toIso8601String(),
        ])->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['intake_id' => ['required', 'ulid'], 'answers' => ['sometimes', 'array']]);
        $intake = Intake::query()->whereKey($data['intake_id'])->where('status', 'open')->firstOrFail();
        $a = app(ApplicationService::class)->applyAsMember($intake, $this->me($request), (array) ($data['answers'] ?? []));

        return response()->json(['data' => $a->only(['id', 'reference', 'status'])], 201);
    }

    public function withdraw(Request $request, string $application): JsonResponse
    {
        $a = Application::query()->where('user_id', $this->me($request)->id)->whereKey($application)->firstOrFail();
        app(ApplicationService::class)->withdraw($a);

        return response()->json(['data' => $a->only(['id', 'status'])]);
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
