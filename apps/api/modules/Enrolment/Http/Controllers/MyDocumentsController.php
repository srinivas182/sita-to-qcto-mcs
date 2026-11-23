<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\EnrolmentSettings;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Models\LearnerDocument;
use Modules\Enrolment\Services\DocumentService;
use Modules\Enrolment\Support\DocumentTypes;
use Modules\Identity\Models\User;

/** The signed-in person's own documents, and what each of their applications still needs. */
final class MyDocumentsController
{
    public function index(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $service = app(DocumentService::class);
        $apps = Application::query()->where('user_id', $me->id)->whereIn('status', Application::ACTIVE)->get();
        $intakes = Intake::query()->whereIn('id', $apps->pluck('intake_id'))->get()->keyBy('id');
        $settings = EnrolmentSettings::current();

        return response()->json(['data' => [
            'documents' => LearnerDocument::query()->where('user_id', $me->id)->latest()->get()->map(fn (LearnerDocument $d) => ApplicantController::document($d))->values()->all(),
            'needed' => $apps->map(fn (Application $a) => ['application_id' => $a->id, 'reference' => $a->reference, 'intake' => $intakes->get($a->intake_id)?->title]
                + $service->requirements($intakes->get($a->intake_id) ?? new Intake, $me->id))->values()->all(),
            'types' => array_map(fn (string $t) => ['type' => $t] + $settings->rule($t), array_keys(DocumentTypes::ALL)),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = app(DocumentService::class)->add($this->me($request), $request->all());

        return response()->json(['data' => ApplicantController::document($d)], 201);
    }

    public function destroy(Request $request, string $document): JsonResponse
    {
        $d = LearnerDocument::query()->where('user_id', $this->me($request)->id)->whereKey($document)->firstOrFail();
        app(DocumentService::class)->remove($d);

        return response()->json(null, 204);
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
