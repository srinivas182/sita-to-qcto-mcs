<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Funding\Models\Funder;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantLearner;
use Modules\Funding\Models\GrantTranche;
use Modules\Identity\Models\User;

/** Funders, grant agreements, the learners they fund and their payment tranches. */
final class GrantService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $input */
    public function saveFunder(?Funder $f, array $input): Funder
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(Funder::KINDS)],
            'seta_code' => ['nullable', 'string', 'max:20'],
            'template' => ['nullable', Rule::in(Funder::TEMPLATES)],
            'contact_email' => ['nullable', 'email', 'max:190'],
        ])->validate();
        $clash = Funder::query()->where('name', $data['name'])->when($f !== null, fn ($q) => $q->whereKeyNot($f?->id))->exists();
        if ($clash) {
            throw ValidationException::withMessages(['name' => 'A funder with this name already exists.']);
        }
        $f ??= new Funder;
        $f->fill($data + ['template' => 'generic'])->save();
        $this->audit->record('funder.saved', $f, [], ['name' => $f->name]);

        return $f;
    }

    /**
     * Create or change an agreement, its learners and its tranches in one go.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveAgreement(Cohort $cohort, ?GrantAgreement $a, User $by, array $input): GrantAgreement
    {
        $v = Validator::make($input, [
            'funder_id' => ['required', 'ulid'],
            'reference' => ['required', 'string', 'max:80'],
            'learners' => ['required', 'integer', 'min:1', 'max:5000'],
            'amount_per_learner' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'funding_kind' => ['required', Rule::in(['full', 'co'])],
            'disability_allowance' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'moa_file_id' => ['nullable', 'ulid'],
            'enrolment_ids' => ['present', 'array'],
            'enrolment_ids.*' => ['ulid'],
            'disabled_ids' => ['nullable', 'array'],
            'disabled_ids.*' => ['ulid'],
            'tranches' => ['required', 'array', 'min:1', 'max:12'],
            'tranches.*.share_pct' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'tranches.*.due_on' => ['nullable', 'date'],
            'tranches.*.milestones' => ['present', 'array'],
            'tranches.*.milestones.*.key' => ['required', Rule::in(array_keys(Milestones::LIST))],
            'tranches.*.milestones.*.months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'tranches.*.evidence' => ['nullable', 'array'],
            'tranches.*.evidence.*.title' => ['required', 'string', 'max:150'],
            'tranches.*.evidence.*.file_id' => ['nullable', 'ulid'],
        ], [
            'ends_on.after' => 'The agreement must end after it starts.',
            'tranches.required' => 'Add at least one payment tranche.',
        ]);
        $v->after(function (ValidatorInstance $v) use ($input, $cohort): void {
            $tranches = is_array($input['tranches'] ?? null) ? $input['tranches'] : [];
            $sum = array_sum(array_map(fn ($t) => (float) (is_array($t) ? ($t['share_pct'] ?? 0) : 0), $tranches));
            if ($sum > 100.001) {
                $v->errors()->add('tranches', 'The tranches add up to '.round($sum, 2).'%; they can’t be more than 100%.');
            }
            foreach ($tranches as $i => $t) {
                if (is_array($t) && ($t['milestones'] ?? []) === [] && ($t['evidence'] ?? []) === []) {
                    $v->errors()->add("tranches.{$i}.milestones", 'Tranche '.($i + 1).' needs at least one milestone or evidence item.');
                }
            }
            $ids = is_array($input['enrolment_ids'] ?? null) ? $input['enrolment_ids'] : [];
            if (count($ids) > (int) ($input['learners'] ?? 0)) {
                $v->errors()->add('enrolment_ids', 'You chose more learners than the agreement funds.');
            }
            if ($ids !== [] && Enrolment::query()->whereIn('id', $ids)->where('cohort_id', $cohort->id)->count() !== count(array_unique($ids))) {
                $v->errors()->add('enrolment_ids', 'Every learner must be enrolled in this cohort.');
            }
        });
        $data = $v->validate();
        if (! Funder::query()->whereKey($data['funder_id'])->exists()) {
            throw ValidationException::withMessages(['funder_id' => 'Choose a funder.']);
        }
        if (! empty($data['moa_file_id']) && ! File::query()->whereKey($data['moa_file_id'])->where('purpose', 'grant_document')->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['moa_file_id' => 'Upload the signed agreement again.']);
        }
        foreach ($data['tranches'] as $i => $t) {
            foreach ($t['evidence'] ?? [] as $e) {
                if (! empty($e['file_id']) && ! File::query()->whereKey($e['file_id'])->where('purpose', 'grant_document')->where('status', 'ready')->exists()) {
                    throw ValidationException::withMessages(["tranches.{$i}.evidence" => 'Upload the evidence for tranche '.($i + 1).' again.']);
                }
            }
        }
        $dup = GrantAgreement::query()->where('funder_id', $data['funder_id'])->where('reference', $data['reference'])
            ->when($a !== null, fn ($q) => $q->whereKeyNot($a?->id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['reference' => 'This funder already has an agreement with that reference.']);
        }

        return DB::transaction(function () use ($cohort, $a, $by, $data): GrantAgreement {
            $new = $a === null;
            $a ??= new GrantAgreement(['cohort_id' => $cohort->id, 'created_by' => $by->id]);
            $a->fill([
                'funder_id' => $data['funder_id'], 'reference' => trim((string) $data['reference']), 'learners' => (int) $data['learners'],
                'amount_per_learner_cents' => self::cents($data['amount_per_learner']), 'funding_kind' => $data['funding_kind'],
                'disability_allowance_cents' => isset($data['disability_allowance']) ? self::cents($data['disability_allowance']) : null,
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'moa_file_id' => $data['moa_file_id'] ?? null,
            ])->save();

            $this->syncLearners($a, array_values(array_unique($data['enrolment_ids'])), $data['disabled_ids'] ?? []);

            $keep = [];
            foreach (array_values($data['tranches']) as $i => $t) {
                $tr = GrantTranche::query()->firstOrNew(['agreement_id' => $a->id, 'number' => $i + 1]);
                $tr->fill([
                    'share_pct' => round((float) $t['share_pct'], 2), 'due_on' => $t['due_on'] ?? null,
                    'milestones' => array_values(array_map(fn (array $m) => array_filter(['key' => $m['key'], 'months' => $m['key'] === 'registers' ? (int) ($m['months'] ?? 1) : null], fn ($x) => $x !== null), $t['milestones'])),
                    'evidence' => array_values(array_map(fn (array $e) => ['title' => trim((string) $e['title']), 'file_id' => $e['file_id'] ?? null], $t['evidence'] ?? [])),
                ])->save();
                $keep[] = $tr->id;
            }
            GrantTranche::query()->where('agreement_id', $a->id)->whereNotIn('id', $keep)->delete();
            $this->audit->record($new ? 'grant.created' : 'grant.changed', $a, [], ['reference' => $a->reference, 'learners' => $a->learners, 'tranches' => count($keep)]);

            return $a;
        });
    }

    /**
     * Learners taken off keep their row (status removed) so past claims still add up.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $disabled
     */
    private function syncLearners(GrantAgreement $a, array $ids, array $disabled): void
    {
        $existing = GrantLearner::query()->where('agreement_id', $a->id)->get()->keyBy('enrolment_id');
        foreach ($ids as $id) {
            $gl = $existing->get($id) ?? new GrantLearner(['agreement_id' => $a->id, 'enrolment_id' => $id]);
            $gl->forceFill(['status' => 'active', 'removed_reason' => null, 'removed_at' => null, 'disabled' => in_array($id, $disabled, true)])->save();
        }
        foreach ($existing as $id => $gl) {
            if (! in_array($id, $ids, true) && $gl->status === 'active') {
                $gl->forceFill(['status' => 'removed', 'removed_reason' => 'Taken off the agreement', 'removed_at' => now()])->save();
            }
        }
    }

    public static function cents(mixed $rand): int
    {
        return (int) round(((float) $rand) * 100);
    }

    /** @return array<string, mixed> */
    public static function present(GrantAgreement $a): array
    {
        return ['id' => $a->id, 'funder_id' => $a->funder_id, 'cohort_id' => $a->cohort_id, 'reference' => $a->reference, 'learners' => $a->learners,
            'amount_per_learner_cents' => $a->amount_per_learner_cents, 'funding_kind' => $a->funding_kind,
            'disability_allowance_cents' => $a->disability_allowance_cents, 'starts_on' => $a->starts_on->toDateString(),
            'ends_on' => $a->ends_on->toDateString(), 'moa_file_id' => $a->moa_file_id, 'status' => $a->status,
            'total_cents' => $a->learners * $a->amount_per_learner_cents];
    }
}
