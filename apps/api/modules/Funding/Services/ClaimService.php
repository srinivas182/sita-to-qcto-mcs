<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Files\Models\File;
use Modules\Funding\Models\ClaimQuery;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantClaim;
use Modules\Funding\Models\GrantLearner;
use Modules\Funding\Models\GrantTranche;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Claims per tranche: the learners who met it are frozen on the claim with its own gapless
 * invoice number; the funder's queries are recorded per learner or document and the claim is
 * sent again once they are fixed.
 */
final class ClaimService
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /** @param  array<string, mixed>  $input */
    public function create(GrantTranche $t, User $by, array $input): GrantClaim
    {
        $a = GrantAgreement::query()->whereKey($t->agreement_id)->firstOrFail();
        $progress = app(GrantProgress::class)->of($a);
        $tranche = null;
        foreach ($progress['tranches'] as $row) {
            if ($row['id'] === $t->id) {
                $tranche = $row;
            }
        }
        if ($tranche === null) {
            throw ValidationException::withMessages(['tranche' => 'This tranche no longer exists.']);
        }
        if ($tranche['evidence_missing'] > 0) {
            throw ValidationException::withMessages(['tranche' => 'Upload the tranche’s evidence before claiming.']);
        }
        $claimed = $this->claimedFor($t->id);
        $disabledClaimed = collect(GrantClaim::query()->where('agreement_id', $a->id)->whereIn('status', GrantClaim::LIVE)->get())
            ->flatMap(fn (GrantClaim $c) => collect($c->learners)->filter(fn (array $l) => $l['disability_cents'] > 0)->pluck('enrolment_id'))->all();
        $disabled = GrantLearner::query()->where('agreement_id', $a->id)->where('disabled', true)->pluck('enrolment_id')->all();
        $withAllowance = ! empty($input['include_disability']) && $a->disability_allowance_cents !== null;

        $learners = [];
        foreach ($progress['learners'] as $l) {
            if ($l['status'] !== 'active' || ! ($l['tranches'][$t->id]['met'] ?? false) || in_array($l['enrolment_id'], $claimed, true)) {
                continue;
            }
            $allowance = $withAllowance && in_array($l['enrolment_id'], $disabled, true) && ! in_array($l['enrolment_id'], $disabledClaimed, true)
                ? (int) $a->disability_allowance_cents : 0;
            $learners[] = ['enrolment_id' => $l['enrolment_id'], 'user_id' => $l['user_id'], 'name' => $l['name'],
                'amount_cents' => (int) $tranche['per_learner_cents'], 'disability_cents' => $allowance];
        }
        if ($learners === []) {
            throw ValidationException::withMessages(['tranche' => 'No learners have met this tranche since its last claim.']);
        }

        $claim = DB::transaction(function () use ($a, $t, $by, $learners): GrantClaim {
            $tenantId = (string) $a->getAttribute('tenant_id');
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id'); // one number at a time
            $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
            $year = now()->year;
            $n = GrantClaim::query()->where('number', 'like', "%-CLM-{$year}-%")->count() + 1;

            return GrantClaim::query()->create([
                'agreement_id' => $a->id, 'tranche_id' => $t->id, 'created_by' => $by->id, 'learners' => $learners,
                'number' => strtoupper((string) $tenant->code).'-CLM-'.$year.'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'amount_cents' => array_sum(array_column($learners, 'amount_cents')) + array_sum(array_column($learners, 'disability_cents')),
            ]);
        });
        $this->audit->record('claim.created', $claim, [], ['number' => $claim->number, 'learners' => count($learners), 'amount_cents' => $claim->amount_cents]);

        return $claim;
    }

    /** @param  array<string, mixed>  $input */
    public function sent(GrantClaim $c, array $input): GrantClaim
    {
        $data = Validator::make($input, ['sent_on' => ['required', 'date', 'before_or_equal:today']])->validate();
        if ($c->status === 'queried' && ClaimQuery::query()->where('claim_id', $c->id)->whereNull('resolved_at')->exists()) {
            throw ValidationException::withMessages(['claim' => 'Fix every query before sending the claim again.']);
        }
        if (! in_array($c->status, ['draft', 'queried'], true)) {
            throw ValidationException::withMessages(['claim' => 'This claim can’t be sent now.']);
        }
        $again = $c->status === 'queried';
        $c->forceFill(['status' => 'sent', 'sent_on' => $data['sent_on']])->save();
        $this->audit->record($again ? 'claim.resent' : 'claim.sent', $c, [], ['sent_on' => $data['sent_on']]);

        return $c;
    }

    /** @param  array<string, mixed>  $input */
    public function query(GrantClaim $c, User $by, array $input): ClaimQuery
    {
        if (! in_array($c->status, ['sent', 'queried'], true)) {
            throw ValidationException::withMessages(['claim' => 'Only a sent claim can be queried.']);
        }
        $data = Validator::make($input, [
            'problem' => ['required', 'string', 'min:3', 'max:500'],
            'enrolment_id' => ['nullable', 'ulid'],
            'raised_on' => ['nullable', 'date', 'before_or_equal:today'],
        ])->validate();
        if (! empty($data['enrolment_id']) && ! in_array($data['enrolment_id'], array_column($c->learners, 'enrolment_id'), true)) {
            throw ValidationException::withMessages(['enrolment_id' => 'That learner is not on this claim.']);
        }
        $q = ClaimQuery::query()->create(['claim_id' => $c->id, 'enrolment_id' => $data['enrolment_id'] ?? null, 'problem' => trim((string) $data['problem']),
            'raised_on' => $data['raised_on'] ?? now()->toDateString(), 'created_by' => $by->id]);
        $c->forceFill(['status' => 'queried'])->save();
        $this->audit->record('claim.queried', $c, [], ['problem' => $q->problem]);

        return $q;
    }

    /** @param  array<string, mixed>  $input */
    public function resolve(ClaimQuery $q, User $by, array $input): ClaimQuery
    {
        $data = Validator::make($input, ['resolution' => ['required', 'string', 'min:3', 'max:500']],
            ['resolution.required' => 'Say what was fixed.'])->validate();
        $q->forceFill(['resolution' => trim((string) $data['resolution']), 'resolved_at' => now(), 'resolved_by' => $by->id])->save();
        $this->audit->record('claim.query_resolved', $q, [], ['resolution' => $q->resolution]);

        return $q;
    }

    /** @param  array<string, mixed>  $input */
    public function paid(GrantClaim $c, array $input): GrantClaim
    {
        if (! in_array($c->status, ['sent', 'queried'], true)) {
            throw ValidationException::withMessages(['claim' => 'Only a sent claim can be marked paid.']);
        }
        $data = Validator::make($input, [
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'proof_file_id' => ['nullable', 'ulid'],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $cents = GrantService::cents($data['amount']);
        if ($cents !== $c->amount_cents && empty($data['note'])) {
            throw ValidationException::withMessages(['note' => 'The amount paid differs from the claim. Note why (e.g. short payment, a learner disallowed).']);
        }
        if (! empty($data['proof_file_id']) && ! File::query()->whereKey($data['proof_file_id'])->where('purpose', 'grant_document')->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['proof_file_id' => 'Upload the remittance again.']);
        }
        $c->forceFill(['status' => 'paid', 'paid_on' => $data['paid_on'], 'paid_cents' => $cents, 'proof_file_id' => $data['proof_file_id'] ?? null,
            'closing_note' => $data['note'] ?? null])->save();
        $this->audit->record('claim.paid', $c, [], ['paid_cents' => $cents, 'claimed_cents' => $c->amount_cents]);

        return $c;
    }

    /**
     * The funder rejected the claim, or the provider voids a draft. Its learners can be claimed
     * again; the invoice number stays used.
     *
     * @param  array<string, mixed>  $input
     */
    public function close(GrantClaim $c, string $as, array $input): GrantClaim
    {
        $allowed = $as === 'void' ? ['draft'] : ['sent', 'queried'];
        if (! in_array($c->status, $allowed, true)) {
            throw ValidationException::withMessages(['claim' => $as === 'void' ? 'Only a draft claim can be cancelled.' : 'Only a sent claim can be rejected.']);
        }
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:3', 'max:500']])->validate();
        $c->forceFill(['status' => $as === 'void' ? 'void' : 'rejected', 'closing_note' => trim((string) $data['reason'])])->save();
        $this->audit->record('claim.'.($as === 'void' ? 'voided' : 'rejected'), $c, [], ['reason' => $c->closing_note]);

        return $c;
    }

    /** @return list<string> enrolment ids already on a live claim for the tranche */
    public function claimedFor(string $trancheId): array
    {
        return array_values(GrantClaim::query()->where('tranche_id', $trancheId)->whereIn('status', GrantClaim::LIVE)->get()
            ->flatMap(fn (GrantClaim $c) => array_column($c->learners, 'enrolment_id'))->unique()->all());
    }

    /** @return array<string, mixed> */
    public static function present(GrantClaim $c, bool $withQueries = true): array
    {
        $out = ['id' => $c->id, 'number' => $c->number, 'agreement_id' => $c->agreement_id, 'tranche_id' => $c->tranche_id, 'status' => $c->status,
            'learners' => $c->learners, 'amount_cents' => $c->amount_cents, 'sent_on' => $c->sent_on?->toDateString(), 'paid_on' => $c->paid_on?->toDateString(),
            'paid_cents' => $c->paid_cents, 'proof_file_id' => $c->proof_file_id, 'closing_note' => $c->closing_note, 'created_at' => $c->created_at?->toIso8601String()];
        if ($withQueries) {
            $out['queries'] = ClaimQuery::query()->where('claim_id', $c->id)->orderBy('created_at')->get()->map(fn (ClaimQuery $q) => [
                'id' => $q->id, 'enrolment_id' => $q->enrolment_id, 'problem' => $q->problem, 'raised_on' => $q->raised_on->toDateString(),
                'resolution' => $q->resolution, 'resolved_at' => $q->resolved_at?->toIso8601String()])->all();
        }

        return $out;
    }
}
