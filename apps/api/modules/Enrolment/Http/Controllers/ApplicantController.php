<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\EnrolmentSettings;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Models\LearnerDocument;
use Modules\Enrolment\Models\Offer;
use Modules\Enrolment\Services\DocumentService;
use Modules\Enrolment\Services\OfferService;
use Modules\Enrolment\Support\DocumentTypes;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;

/** One application in full (documents and their checks), reviewing documents, and the document rules. */
final class ApplicantController
{
    public function show(Request $request, string $application): JsonResponse
    {
        Gate::authorize('applications.view');
        $a = $this->application($request, $application, 'applications.view');
        $intake = Intake::query()->whereKey($a->intake_id)->firstOrFail();
        $u = User::query()->whereKey($a->user_id)->firstOrFail();

        return response()->json(['data' => $a->only(['id', 'reference', 'status', 'answers', 'reason', 'waitlist_position', 'source']) + [
            'submitted_at' => $a->submitted_at->toIso8601String(),
            'documents_requested_at' => $a->documents_requested_at?->toIso8601String(),
            'intake' => ['id' => $intake->id, 'title' => $intake->title, 'questions' => $intake->questions],
            'applicant' => [
                'id' => $u->id, 'name' => $u->fullName(), 'phone' => $u->phone_e164, 'email' => $u->email,
                'id_number' => $u->maskedIdNumber(), 'id_type' => $u->id_type?->value, 'dob' => $u->dob?->toDateString(),
                'age' => $u->dob !== null ? (int) floor(CarbonImmutable::parse($u->dob)->diffInYears(CarbonImmutable::today())) : null,
            ],
            'requirements' => app(DocumentService::class)->requirements($intake, $u->id),
            'offer' => ($o = Offer::query()->where('application_id', $a->id)->latest()->first()) !== null ? app(OfferService::class)->present($o) : null,
            'places_left' => app(OfferService::class)->placesLeft($intake),
            'documents' => LearnerDocument::query()->where('user_id', $u->id)->latest()->get()->map(fn (LearnerDocument $d) => self::document($d))->values()->all(),
        ]]);
    }

    public function review(Request $request, string $document): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'id_confirmed' => ['sometimes', 'boolean'],
        ]);
        $d = LearnerDocument::query()->whereKey($document)->firstOrFail();
        // Only for someone who applied to an intake this person may manage.
        $intakes = Application::query()->where('user_id', $d->user_id)->pluck('intake_id');
        abort_if($intakes->isEmpty(), 404);
        $this->intakeAllowed($request, (string) $intakes->first(), 'applications.manage');
        /** @var User $by */
        $by = $request->user();
        $d = app(DocumentService::class)->review($d, $data['decision'], $data['reason'] ?? null, (bool) ($data['id_confirmed'] ?? false), $by);

        return response()->json(['data' => self::document($d)]);
    }

    public function requestDocuments(Request $request, string $application): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $a = $this->application($request, $application, 'applications.manage');

        return response()->json(['data' => ['requested' => app(DocumentService::class)->requestMissing($a, $data['note'] ?? null)]]);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('applications.view');

        return response()->json(['data' => $this->rules()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate([
            'document_rules' => ['required', 'array'],
            'document_rules.*.certified' => ['sometimes', 'boolean'],
            'document_rules.*.max_age_days' => ['nullable', 'integer', 'between:7,730'],
        ]);
        $rules = array_intersect_key($data['document_rules'], DocumentTypes::ALL);
        $s = EnrolmentSettings::current();
        $s->forceFill(['document_rules' => array_replace($s->document_rules ?? [], $rules)])->save();

        return response()->json(['data' => $this->rules()]);
    }

    /** @return array<string, mixed> */
    public static function document(LearnerDocument $d): array
    {
        return $d->only(['id', 'type', 'file_id', 'certified', 'certified_by', 'checks', 'status', 'reason']) + [
            'label' => DocumentTypes::label($d->type), 'certified_on' => $d->certified_on?->toDateString(),
            'mime' => File::query()->whereKey($d->file_id)->value('mime'),
            'added_at' => $d->created_at->toIso8601String(), 'reviewed_at' => $d->reviewed_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        $s = EnrolmentSettings::current();

        return array_map(fn (string $type) => ['type' => $type] + $s->rule($type), array_keys(DocumentTypes::ALL));
    }

    private function application(Request $request, string $id, string $permission): Application
    {
        $a = Application::query()->whereKey($id)->firstOrFail();
        $this->intakeAllowed($request, $a->intake_id, $permission);

        return $a;
    }

    private function intakeAllowed(Request $request, string $intakeId, string $permission): void
    {
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        if ($centres === null) {
            return;
        }
        $cohort = Intake::query()->whereKey($intakeId)->value('cohort_id');
        abort_unless(in_array(Cohort::query()->whereKey($cohort)->value('centre_id'), $centres, true), 404);
    }
}
