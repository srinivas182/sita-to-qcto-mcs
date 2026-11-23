<?php

declare(strict_types=1);

namespace Modules\Cohorts\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Cohorts\Models\Placement;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Models\Centre;

/**
 * Plans cohorts and opens them when the readiness check passes. Changes to an open cohort
 * are checked again and refused if they would make it fail.
 */
final class CohortService
{
    public function __construct(private readonly AuditLogger $audit, private readonly Readiness $readiness) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>|null  $centres  centres the user may plan for (null: all)
     */
    public function save(array $input, ?Cohort $existing, ?array $centres): Cohort
    {
        $req = $existing ? 'sometimes' : 'required';
        $data = Validator::make($input, [
            'name' => [$req, 'string', 'min:3', 'max:150'],
            'qualification_id' => [$req, 'ulid'],
            'centre_id' => [$req, 'ulid'],
            'mode' => [$req, Rule::in(Programme::MODES)],
            'intake_on' => [$req, 'date'],
            'planned_end_on' => [$req, 'date', 'after:intake_on'],
            'capacity' => [$req, 'integer', 'between:1,500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['planned_end_on.after' => 'The planned end must be after the intake.'])->validate();

        if ($existing !== null && $existing->status !== 'draft' && array_intersect(array_keys($data), ['qualification_id', 'centre_id']) !== []) {
            throw ValidationException::withMessages(['qualification_id' => 'The qualification and centre are fixed once a cohort opens.']);
        }
        if (isset($data['qualification_id']) && ! Qualification::query()->whereKey((string) $data['qualification_id'])->exists()) {
            throw ValidationException::withMessages(['qualification_id' => 'Choose a qualification from the catalogue.']);
        }
        if (isset($data['centre_id'])) {
            if (! Centre::query()->whereKey((string) $data['centre_id'])->exists() || ($centres !== null && ! in_array($data['centre_id'], $centres, true))) {
                throw ValidationException::withMessages(['centre_id' => 'Choose one of your centres.']);
            }
        }
        if ($existing !== null && isset($data['intake_on']) && ! isset($data['planned_end_on']) && strtotime((string) $data['intake_on']) >= $existing->planned_end_on->getTimestamp()) {
            throw ValidationException::withMessages(['intake_on' => 'The intake must be before the planned end.']);
        }

        $cohort = $existing ?? new Cohort;

        return $this->guarded($cohort, function () use ($cohort, $data, $existing): Cohort {
            $cohort->fill($data);
            if (! $cohort->exists) {
                $cohort->forceFill(['status' => 'draft']);
            }
            $cohort->save();
            $this->audit->record($existing ? 'cohort.updated' : 'cohort.planned', $cohort, [], ['name' => $cohort->name]);

            return $cohort;
        });
    }

    /** @param  list<array{user_id: string, role: string}>|mixed  $input */
    public function setStaff(Cohort $cohort, mixed $input): void
    {
        $data = Validator::make(['staff' => $input], [
            'staff' => ['present', 'array', 'max:30'],
            'staff.*.user_id' => ['required', 'ulid'],
            'staff.*.role' => ['required', Rule::in(CohortStaff::ROLES)],
        ])->validate()['staff'];
        $users = array_values(array_unique(array_column($data, 'user_id')));
        if (UserTenant::query()->whereIn('user_id', $users)->count() !== count($users)) {
            throw ValidationException::withMessages(['staff' => 'Choose people from your own team.']);
        }
        $this->editable($cohort);
        $this->guarded($cohort, function () use ($cohort, $data): void {
            CohortStaff::query()->where('cohort_id', $cohort->id)->delete();
            $unique = [];
            foreach ($data as $s) {
                $unique[$s['user_id'].'|'.$s['role']] = $s;
            }
            foreach ($unique as $s) {
                CohortStaff::query()->create(['cohort_id' => $cohort->id, 'user_id' => $s['user_id'], 'role' => $s['role']]);
            }
            $this->audit->record('cohort.staff_set', $cohort, [], ['count' => count($data)]);
        });
    }

    /** @param  list<array{site_id: string, mentor_id?: string|null, planned_learners: int}>|mixed  $input */
    public function setPlacements(Cohort $cohort, mixed $input): void
    {
        $data = Validator::make(['placements' => $input], [
            'placements' => ['present', 'array', 'max:50'],
            'placements.*.site_id' => ['required', 'ulid', 'distinct'],
            'placements.*.mentor_id' => ['nullable', 'ulid'],
            'placements.*.planned_learners' => ['required', 'integer', 'between:1,500'],
        ], ['placements.*.site_id.distinct' => 'Each workplace once.'])->validate()['placements'];
        $sites = EmployerSite::query()->whereIn('id', array_column($data, 'site_id'))->get()->keyBy('id');
        foreach ($data as $i => $p) {
            $site = $sites->get($p['site_id']) ?? throw ValidationException::withMessages(["placements.{$i}.site_id" => 'Choose one of your employers\' workplaces.']);
            if (! empty($p['mentor_id']) && ! Mentor::query()->whereKey((string) $p['mentor_id'])->where('employer_id', $site->employer_id)->exists()) {
                throw ValidationException::withMessages(["placements.{$i}.mentor_id" => 'Choose a mentor from that employer.']);
            }
        }
        $this->editable($cohort);
        $this->guarded($cohort, function () use ($cohort, $data, $sites): void {
            Placement::query()->where('cohort_id', $cohort->id)->delete();
            foreach ($data as $p) {
                Placement::query()->create(['cohort_id' => $cohort->id, 'site_id' => $p['site_id'], 'employer_id' => $sites->get($p['site_id'])?->employer_id,
                    'mentor_id' => $p['mentor_id'] ?? null, 'planned_learners' => $p['planned_learners']]);
            }
            $this->audit->record('cohort.placements_set', $cohort, [], ['learners' => array_sum(array_column($data, 'planned_learners'))]);
        });
    }

    /** @return array<string, mixed> the readiness check it opened with */
    public function open(Cohort $cohort, User $by): array
    {
        if ($cohort->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a planned cohort can be opened.']);
        }

        return DB::transaction(function () use ($cohort, $by): array {
            Cohort::query()->whereKey($cohort->id)->lockForUpdate()->first();
            $check = $this->readiness->check($cohort);
            if (! $check['ok']) {
                throw ValidationException::withMessages(['readiness' => 'Fix the failing items first: '.implode('; ', array_column(array_filter($check['items'], fn ($i) => $i['status'] === 'fail'), 'title')).'.']);
            }
            $cohort->forceFill(['status' => 'open', 'opened_at' => now(), 'opened_by' => $by->id, 'readiness' => $check + ['at' => now()->toIso8601String()], 'watch_fails' => []])->save();
            $this->audit->record('cohort.opened', $cohort, [], ['warnings' => $check['counts']['warn']]);

            return $check;
        });
    }

    public function close(Cohort $cohort, string $status): void
    {
        $allowed = $status === 'closed' ? ['open'] : ['draft', 'open'];
        if (! in_array($cohort->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $status === 'closed' ? 'Only an open cohort can be closed.' : 'This cohort is already finished.']);
        }
        $cohort->forceFill(['status' => $status])->save();
        $this->audit->record($status === 'closed' ? 'cohort.closed' : 'cohort.cancelled', $cohort);
    }

    private function editable(Cohort $cohort): void
    {
        if (! in_array($cohort->status, ['draft', 'open'], true)) {
            throw ValidationException::withMessages(['status' => 'This cohort is finished and can no longer change.']);
        }
    }

    /**
     * Run a change; for an open cohort, refuse it (and roll back) if it makes the check fail.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    private function guarded(Cohort $cohort, Closure $change): mixed
    {
        return DB::transaction(function () use ($cohort, $change) {
            $result = $change();
            if ($cohort->status === 'open') {
                $fails = array_filter($this->readiness->check($cohort->refresh())['items'], fn ($i) => $i['status'] === 'fail');
                if ($fails !== []) {
                    throw ValidationException::withMessages(['readiness' => 'This change would leave the open cohort failing: '.implode('; ', array_column($fails, 'title')).'.']);
                }
            }

            return $result;
        });
    }
}
