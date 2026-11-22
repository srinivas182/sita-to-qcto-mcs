<?php

declare(strict_types=1);

namespace Modules\Qualifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Qualifications\Models\Suggestion;
use Modules\Qualifications\Notifications\SuggestionDecided;
use Modules\Qualifications\Support\CatalogueChecks;
use Modules\Qualifications\Support\ModuleImport;

/**
 * The shared catalogue, kept by catalogue editors (our team or a provider's quality
 * manager). Runs in the platform context: only it may write catalogue rows.
 */
final class CatalogueService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $input */
    public function save(array $input, ?Qualification $existing, User $actor): Qualification
    {
        $data = Validator::make($input, [
            'type' => [$existing ? 'sometimes' : 'required', Rule::in(array_keys(Qualification::TYPES))],
            'saqa_id' => ['nullable', 'regex:/^\d{4,8}$/', Rule::unique('qualifications', 'saqa_id')->ignore($existing?->id)],
            'curriculum_code' => ['nullable', 'regex:/^\d{6,12}$/', Rule::unique('qualifications', 'curriculum_code')->ignore($existing?->id)],
            'title' => [$existing ? 'sometimes' : 'required', 'string', 'min:5', 'max:300'],
            'nqf_level' => [$existing ? 'sometimes' : 'required', 'integer', 'between:1,10'],
            'credits' => [$existing ? 'sometimes' : 'required', 'integer', 'between:1,1000'],
            'quality_partner' => ['nullable', 'string', 'max:200'],
            'assessment_partner' => ['nullable', 'string', 'max:200'],
            'eisa' => ['sometimes', 'boolean'],
            'registration_end' => ['nullable', 'date'],
            'last_enrolment_on' => ['nullable', 'date'],
            'last_achievement_on' => ['nullable', 'date'],
            'successor_ids' => ['nullable', 'array', 'max:10'],
            'successor_ids.*' => ['ulid', 'exists:qualifications,id'],
            'credit_rules' => ['nullable', 'array'],
            'credit_rules.fundamental' => ['nullable', 'integer', 'min:0'],
            'credit_rules.core' => ['nullable', 'integer', 'min:0'],
            'credit_rules.elective_min' => ['nullable', 'integer', 'min:0'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'saqa_id.regex' => 'A SAQA ID is 4–8 digits.',
            'curriculum_code.regex' => 'A curriculum code is 6–12 digits, e.g. 641201001.',
        ])->validate();

        if (isset($data['successor_ids']) && $existing !== null && in_array($existing->id, $data['successor_ids'], true)) {
            throw ValidationException::withMessages(['successor_ids' => 'A qualification cannot replace itself.']);
        }

        $q = $existing ?? new Qualification;
        $before = $existing?->only(['last_enrolment_on', 'last_achievement_on', 'registration_end', 'status']);
        $q->fill($data);
        if ($existing !== null && $existing->status === 'published' && $q->isDirty()) {
            $q->version++;
        }
        $q->save();

        // Dates come from gazettes and change: keep each change in the audit trail.
        $this->audit->record($existing ? 'qualification.updated' : 'qualification.created', $q, array_filter([
            'before' => $before, 'after' => $q->only(['last_enrolment_on', 'last_achievement_on', 'registration_end', 'status']),
        ]), ['by' => $actor->id]);

        return $q;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array<string, mixed>>, applied: bool}
     */
    public function importModules(Qualification $q, string $text, bool $apply, User $actor): array
    {
        $result = ModuleImport::parse($text, $q->isLegacy());
        if (! $apply || $result['errors'] !== [] || $result['rows'] === []) {
            return $result + ['applied' => false];
        }

        DB::transaction(function () use ($q, $result): void {
            $q->modules()->delete();
            foreach ($result['rows'] as $i => $row) {
                unset($row['line']);
                QualificationModule::query()->create($row + ['qualification_id' => $q->id, 'sort' => $i]);
            }
            if ($q->status === 'published') {
                $q->version++;
                $q->save();
            }
        });
        $this->audit->record('qualification.modules_imported', $q, [], ['by' => $actor->id, 'count' => count($result['rows'])]);

        return $result + ['applied' => true];
    }

    /** @return array{errors: list<string>, warnings: list<string>, totals: array<string, int>} */
    public function checks(Qualification $q): array
    {
        return CatalogueChecks::run($q, $q->modules()->get());
    }

    public function publish(Qualification $q, User $actor): Qualification
    {
        $checks = $this->checks($q);
        if ($checks['errors'] !== []) {
            throw ValidationException::withMessages(['qualification' => $checks['errors']]);
        }

        $q->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id])->save();
        $this->audit->record('qualification.published', $q, [], ['by' => $actor->id, 'warnings' => $checks['warnings']]);

        return $q;
    }

    /** Withdrawn qualifications stay readable for providers that offer them, but cannot be added. */
    public function withdraw(Qualification $q, string $reason, User $actor): Qualification
    {
        $q->forceFill(['status' => 'withdrawn', 'notes' => trim(($q->notes ?? '')."\nWithdrawn: {$reason}")])->save();
        $this->audit->record('qualification.withdrawn', $q, [], ['by' => $actor->id, 'reason' => $reason]);

        return $q;
    }

    public function decide(Suggestion $s, bool $accept, string $response, ?string $qualificationId, User $actor): Suggestion
    {
        if ($s->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'This suggestion has already been answered.']);
        }

        $s->forceFill([
            'status' => $accept ? 'accepted' : 'declined',
            'response' => $response,
            'qualification_id' => $qualificationId ?? $s->qualification_id,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ])->save();
        $this->audit->record('catalogue.suggestion_'.($accept ? 'accepted' : 'declined'), $s, [], ['by' => $actor->id], $s->tenant_id);

        $email = User::query()->whereKey($s->submitted_by)->value('email');
        if (is_string($email)) {
            Notification::route('mail', $email)->notify(new SuggestionDecided($s->title, $accept, $response));
        }

        return $s;
    }
}
