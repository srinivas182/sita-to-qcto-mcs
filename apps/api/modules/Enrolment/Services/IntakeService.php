<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Support\DocumentTypes;
use Modules\Enrolment\Support\Questions;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;

/** Publishing a cohort for applications. */
final class IntakeService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>|null  $centres  centres the user may manage (null: all)
     */
    public function save(array $input, ?Intake $existing, ?array $centres, User $by): Intake
    {
        $req = $existing ? 'sometimes' : 'required';
        $data = Validator::make($input, [
            'cohort_id' => [$existing ? 'prohibited' : 'required', 'ulid'],
            'title' => [$req, 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'opens_on' => [$req, 'date'],
            'closes_on' => [$req, 'date', 'after_or_equal:opens_on'],
            'places' => ['sometimes', 'integer', 'between:1,2000'],
            'min_age' => ['nullable', 'integer', 'between:14,80'],
            'max_age' => ['nullable', 'integer', 'between:14,99', 'gte:min_age'],
            'document_types' => ['nullable', 'array', 'max:10'],
            'document_types.*' => ['string', 'distinct', Rule::in(array_keys(DocumentTypes::ALL))],
            'questions' => ['nullable', 'array', 'max:15'],
            'questions.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,30}$/', 'distinct'],
            'questions.*.label' => ['required', 'string', 'min:3', 'max:200'],
            'questions.*.type' => ['required', Rule::in(Questions::TYPES)],
            'questions.*.options' => ['required_if:questions.*.type,choice', 'array', 'max:12'],
            'questions.*.options.*' => ['string', 'max:100'],
            'questions.*.required' => ['sometimes', 'boolean'],
        ], ['closes_on.after_or_equal' => 'Applications must close on or after the day they open.'])->validate();

        $cohort = Cohort::query()->whereKey($existing->cohort_id ?? (string) ($data['cohort_id'] ?? ''))->first()
            ?? throw ValidationException::withMessages(['cohort_id' => 'Choose one of your cohorts.']);
        if ($centres !== null && ! in_array($cohort->centre_id, $centres, true)) {
            throw ValidationException::withMessages(['cohort_id' => 'Choose a cohort at one of your centres.']);
        }
        if (! in_array($cohort->status, ['draft', 'open'], true)) {
            throw ValidationException::withMessages(['cohort_id' => 'That cohort is finished.']);
        }
        $closes = isset($data['closes_on']) ? CarbonImmutable::parse((string) $data['closes_on']) : CarbonImmutable::parse($existing?->closes_on);
        if ($closes->gte(CarbonImmutable::parse($cohort->planned_end_on))) {
            throw ValidationException::withMessages(['closes_on' => 'Applications must close before the cohort ends.']);
        }

        $intake = $existing ?? new Intake(['cohort_id' => $cohort->id, 'places' => $cohort->capacity]);
        unset($data['cohort_id']);
        $intake->fill($data);
        if (! $intake->exists) {
            $intake->forceFill(['slug' => strtolower(Str::random(8)), 'status' => 'draft', 'created_by' => $by->id]);
        }
        $intake->save();
        $this->audit->record($existing ? 'intake.updated' : 'intake.created', $intake, [], ['title' => $intake->title]);

        return $intake;
    }

    public function publish(Intake $intake): Intake
    {
        $cohort = Cohort::query()->whereKey($intake->cohort_id)->firstOrFail();
        $qualification = Qualification::query()->whereKey($cohort->qualification_id)->firstOrFail();
        // Learners enrol on the cohort's intake date: it must still take enrolments then.
        if (! $qualification->enrolmentOpen(CarbonImmutable::parse($cohort->intake_on))) {
            throw ValidationException::withMessages(['status' => "{$qualification->title} does not take new enrolments on this cohort's intake date."]);
        }
        if (! in_array($cohort->status, ['draft', 'open'], true)) {
            throw ValidationException::withMessages(['status' => 'That cohort is finished.']);
        }
        $intake->forceFill(['status' => 'open'])->save();
        $this->audit->record('intake.published', $intake);

        return $intake;
    }

    public function close(Intake $intake): Intake
    {
        $intake->forceFill(['status' => 'closed'])->save();
        $this->audit->record('intake.closed', $intake);

        return $intake;
    }
}
