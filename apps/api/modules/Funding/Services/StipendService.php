<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Modules\Assessment\Services\ResultNotifier;
use Modules\Audit\AuditLogger;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\RegisterSignature;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Funding\Models\BankAccount;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantLearner;
use Modules\Funding\Models\StipendLine;
use Modules\Funding\Models\StipendRun;
use Modules\Identity\Models\User;

/**
 * Learner stipends: proposed each month from the signed attendance register, checked and
 * confirmed by the provider, exported as a bank file, then marked paid with proof. The
 * platform never moves money (Sprint 9 decision 2).
 */
final class StipendService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $input */
    public function saveRule(GrantAgreement $a, array $input): GrantAgreement
    {
        $v = Validator::make($input, [
            'stipend' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'full_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'hold_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'prorata' => ['required', 'boolean'],
        ]);
        $v->after(function (ValidatorInstance $v) use ($input): void {
            if ((int) ($input['hold_pct'] ?? 0) > (int) ($input['full_pct'] ?? 0)) {
                $v->errors()->add('hold_pct', 'The hold level must be at or below the full-stipend level.');
            }
        });
        $d = $v->validated();
        $a->forceFill(['stipend_cents' => isset($d['stipend']) ? GrantService::cents($d['stipend']) : null, 'stipend_full_pct' => (int) $d['full_pct'],
            'stipend_hold_pct' => (int) $d['hold_pct'], 'stipend_prorata' => (bool) $d['prorata']])->save();
        $this->audit->record('stipend.rule', $a, [], ['stipend_cents' => $a->stipend_cents, 'full' => $a->stipend_full_pct, 'hold' => $a->stipend_hold_pct]);

        return $a;
    }

    /** @param  array<string, mixed>  $input */
    public function saveBank(string $userId, User $by, array $input): BankAccount
    {
        $data = Validator::make($input, [
            'holder_name' => ['required', 'string', 'max:150'],
            'bank' => ['required', Rule::in(array_keys(BankAccount::BANKS))],
            'branch_code' => ['nullable', 'regex:/^\d{6}$/'],
            'account_number' => ['required', 'regex:/^\d{6,16}$/'],
            'account_type' => ['required', Rule::in(array_keys(BankAccount::TYPES))],
            'proof_file_id' => ['nullable', 'ulid'],
        ], [
            'account_number.regex' => 'An account number is 6 to 16 digits, no spaces.',
            'branch_code.regex' => 'A branch code is 6 digits.',
        ])->validate();
        $branch = $data['branch_code'] ?? '' ?: BankAccount::BANKS[$data['bank']][1];
        if ($branch === '') {
            throw ValidationException::withMessages(['branch_code' => 'Enter the branch code for this bank.']);
        }
        if (! empty($data['proof_file_id']) && ! File::query()->whereKey($data['proof_file_id'])->whereIn('purpose', ['bank_proof', 'grant_document'])->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['proof_file_id' => 'Upload the bank letter again.']);
        }
        $b = BankAccount::query()->firstOrNew(['user_id' => $userId]);
        $changed = $b->exists ? ['last4' => $b->last4, 'bank' => $b->bank] : [];
        $b->fill(['holder_name' => trim((string) $data['holder_name']), 'bank' => $data['bank'], 'branch_code' => $branch, 'account_number' => $data['account_number'],
            'last4' => substr((string) $data['account_number'], -4), 'account_type' => $data['account_type'], 'proof_file_id' => $data['proof_file_id'] ?? $b->proof_file_id,
            'updated_by' => $by->id])->save();
        // Never log the account number itself.
        $this->audit->record('bank.saved', $b, $changed, ['last4' => $b->last4, 'bank' => $b->bank, 'by_learner' => $by->id === $userId]);

        return $b;
    }

    /** Propose (or re-propose while still a draft) one month's stipends from that month's register. */
    public function propose(GrantAgreement $a, string $month, User $by): StipendRun
    {
        if ($a->stipend_cents === null) {
            throw ValidationException::withMessages(['month' => 'Set the monthly stipend on the agreement first.']);
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Choose a month.']);
        }
        $start = CarbonImmutable::parse($month.'-01');
        $register = AttendanceRegister::query()->where('cohort_id', $a->cohort_id)->whereDate('month', $start->toDateString())->first();
        if ($register === null || ! in_array($register->status, ['complete', 'submitted'], true)) {
            throw ValidationException::withMessages(['month' => 'The '.$start->format('F Y').' register must be made and signed by the facilitator first. Stipends are paid on the signed register.']);
        }
        $run = StipendRun::query()->where('agreement_id', $a->id)->whereDate('month', $start->toDateString())->first();
        if ($run !== null && $run->status !== 'draft') {
            throw ValidationException::withMessages(['month' => 'This month is already confirmed.']);
        }

        return DB::transaction(function () use ($a, $start, $register, $run, $by): StipendRun {
            $run ??= StipendRun::query()->create(['agreement_id' => $a->id, 'month' => $start->toDateString(), 'created_by' => $by->id]);
            $run->forceFill(['register_id' => $register->id])->save();
            $overrides = StipendLine::query()->where('run_id', $run->id)->whereNotNull('override_reason')->get()->keyBy('enrolment_id');
            StipendLine::query()->where('run_id', $run->id)->delete();

            $learners = GrantLearner::query()->where('agreement_id', $a->id)->where('status', 'active')->pluck('enrolment_id');
            $enrolments = Enrolment::query()->whereIn('id', $learners)->get();
            $rows = collect($register->grid['learners'])->keyBy('user_id');
            $sigs = RegisterSignature::query()->where('register_id', $register->id)->where('role', 'learner')->pluck('status', 'user_id');
            $banks = BankAccount::query()->whereIn('user_id', $enrolments->pluck('user_id'))->pluck('id', 'user_id');
            foreach ($enrolments as $e) {
                [$pct, $cents, $status, $reason] = self::decide($a, $rows->get($e->user_id), $sigs->get($e->user_id), $banks->has($e->user_id));
                $line = new StipendLine(['run_id' => $run->id, 'enrolment_id' => $e->id, 'user_id' => $e->user_id, 'attendance_pct' => $pct,
                    'proposed_cents' => $cents, 'amount_cents' => $cents, 'status' => $status, 'reason' => $reason]);
                $o = $overrides->get($e->id);
                if ($o !== null) {
                    $line->fill(['amount_cents' => $o->amount_cents, 'status' => $o->status, 'override_reason' => $o->override_reason]);
                }
                $line->save();
            }
            $this->total($run);
            $this->audit->record('stipend.proposed', $run, [], ['month' => $start->format('Y-m'), 'learners' => $enrolments->count()]);

            return $run;
        });
    }

    /**
     * The rule for one learner and month: [attendance %, cents, pay|hold, reason].
     *
     * @param  array<string, mixed>|null  $row
     * @return array{0: int|null, 1: int, 2: string, 3: string|null}
     */
    public static function decide(GrantAgreement $a, ?array $row, ?string $signature, bool $hasBank): array
    {
        $full = (int) $a->stipend_cents;
        if ($row === null) {
            return [null, 0, 'hold', 'Not on this month’s register.'];
        }
        $pct = isset($row['pct']) ? (int) $row['pct'] : null;
        if ($pct === null) {
            $pct = ((int) ($row['workplace_days'] ?? 0)) > 0 ? 100 : null;
        }
        if ($pct === null) {
            return [null, 0, 'hold', 'No attendance recorded this month.'];
        }
        if ($signature === 'disputed') {
            return [$pct, 0, 'hold', 'The learner disputed the register.'];
        }
        if ($signature !== 'signed') {
            return [$pct, 0, 'hold', 'The learner has not signed the register yet.'];
        }
        if ($pct < $a->stipend_hold_pct) {
            return [$pct, 0, 'hold', "Attendance {$pct}% is below {$a->stipend_hold_pct}%."];
        }
        $cents = $pct >= $a->stipend_full_pct || ! $a->stipend_prorata ? $full : (int) round($full * $pct / 100);
        if (! $hasBank) {
            return [$pct, $cents, 'hold', 'No bank details.'];
        }

        return [$pct, $cents, 'pay', $cents < $full ? "Pro-rata: attendance {$pct}%." : null];
    }

    /** @param  array<string, mixed>  $input */
    public function override(StipendLine $line, User $by, array $input): StipendLine
    {
        $run = StipendRun::query()->whereKey($line->run_id)->firstOrFail();
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['line' => 'This month is already confirmed.']);
        }
        $data = Validator::make($input, [
            'amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'status' => ['required', Rule::in(['pay', 'hold'])],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], ['reason.required' => 'Say why you are changing the proposed stipend.'])->validate();
        if ($data['status'] === 'pay' && ! BankAccount::query()->where('user_id', $line->user_id)->exists()) {
            throw ValidationException::withMessages(['status' => 'Add the learner’s bank details before paying.']);
        }
        $before = ['amount_cents' => $line->amount_cents, 'status' => $line->status];
        $line->fill(['amount_cents' => $data['status'] === 'hold' ? 0 : GrantService::cents($data['amount']), 'status' => $data['status'],
            'override_reason' => trim((string) $data['reason'])])->save();
        $this->total($run);
        $this->audit->record('stipend.changed', $line, $before, ['amount_cents' => $line->amount_cents, 'status' => $line->status, 'reason' => $line->override_reason, 'by' => $by->id]);

        return $line;
    }

    public function confirm(StipendRun $run, User $by): StipendRun
    {
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['run' => 'This month is already confirmed.']);
        }
        $paying = StipendLine::query()->where('run_id', $run->id)->where('status', 'pay')->pluck('user_id');
        $missing = $paying->diff(BankAccount::query()->whereIn('user_id', $paying)->pluck('user_id'));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['run' => $missing->count().' learner(s) to be paid have no bank details. Propose the month again.']);
        }
        $run->forceFill(['status' => 'confirmed', 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();
        $this->total($run);
        $this->audit->record('stipend.confirmed', $run, [], ['total_cents' => $run->total_cents]);

        return $run;
    }

    /**
     * The bulk payment file (CSV) most SA business banking accepts for import. Not stored.
     */
    public function bankFile(StipendRun $run, User $by): string
    {
        if ($run->status === 'draft') {
            throw ValidationException::withMessages(['run' => 'Confirm the month before making the bank file.']);
        }
        $lines = StipendLine::query()->where('run_id', $run->id)->where('status', 'pay')->where('amount_cents', '>', 0)->get();
        $banks = BankAccount::query()->whereIn('user_id', $lines->pluck('user_id'))->get()->keyBy('user_id');
        $ref = 'STIPEND '.strtoupper($run->month->format('M Y'));
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Could not make the bank file.');
        }
        fputcsv($out, ['Recipient name', 'Branch code', 'Account number', 'Account type', 'Amount', 'Recipient reference', 'Own reference'], ',', '"', '');
        foreach ($lines as $l) {
            $b = $banks->get($l->user_id);
            if ($b === null) {
                continue;
            }
            fputcsv($out, [self::csvSafe($b->holder_name), $b->branch_code, $b->account_number, BankAccount::TYPES[$b->account_type] ?? 1,
                number_format($l->amount_cents / 100, 2, '.', ''), $ref, $ref], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record('stipend.bank_file', $run, [], ['lines' => $lines->count(), 'total_cents' => $lines->sum('amount_cents'), 'by' => $by->id]);

        return $csv;
    }

    /** @param  array<string, mixed>  $input */
    public function paid(StipendRun $run, User $by, array $input): StipendRun
    {
        if ($run->status !== 'confirmed') {
            throw ValidationException::withMessages(['run' => $run->status === 'paid' ? 'Already marked paid.' : 'Confirm the month first.']);
        }
        $data = Validator::make($input, ['paid_on' => ['required', 'date', 'before_or_equal:today'], 'proof_file_id' => ['required', 'ulid']],
            ['proof_file_id.required' => 'Upload the bank’s proof of payment.'])->validate();
        if (! File::query()->whereKey($data['proof_file_id'])->where('purpose', 'grant_document')->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['proof_file_id' => 'Upload the proof of payment again.']);
        }
        $run->forceFill(['status' => 'paid', 'paid_on' => $data['paid_on'], 'proof_file_id' => $data['proof_file_id']])->save();
        $this->audit->record('stipend.paid', $run, [], ['paid_on' => $data['paid_on'], 'total_cents' => $run->total_cents]);
        $month = $run->month->format('F Y');
        foreach (StipendLine::query()->where('run_id', $run->id)->get() as $l) {
            $body = $l->status === 'pay' ? 'R'.number_format($l->amount_cents / 100, 2).' was paid into your bank account.' : 'Your stipend is on hold: '.($l->override_reason ?? $l->reason);
            app(ResultNotifier::class)->tell($l->user_id, "Stipend for {$month}", $body, ['type' => 'stipend']);
        }

        return $run;
    }

    /** @return list<array<string, mixed>> */
    public function mine(User $u): array
    {
        $lines = StipendLine::query()->where('user_id', $u->id)->get();
        $runs = StipendRun::query()->whereIn('id', $lines->pluck('run_id'))->whereIn('status', ['confirmed', 'paid'])->get()->keyBy('id');

        return array_values($lines->filter(fn (StipendLine $l) => $runs->has($l->run_id))->map(function (StipendLine $l) use ($runs): array {
            $r = $runs->get($l->run_id);

            return ['month' => $r?->month->format('Y-m'), 'amount_cents' => $l->amount_cents, 'attendance_pct' => $l->attendance_pct,
                'status' => $l->status === 'hold' ? 'held' : ($r?->status === 'paid' ? 'paid' : 'pending'), 'paid_on' => $r?->paid_on?->toDateString(),
                'reason' => $l->status === 'hold' ? ($l->override_reason ?? $l->reason) : null];
        })->sortByDesc('month')->all());
    }

    /** @return array<string, mixed> */
    public static function present(StipendRun $r): array
    {
        return ['id' => $r->id, 'agreement_id' => $r->agreement_id, 'month' => $r->month->format('Y-m'), 'status' => $r->status, 'total_cents' => $r->total_cents,
            'confirmed_at' => $r->confirmed_at?->toIso8601String(), 'paid_on' => $r->paid_on?->toDateString(), 'proof_file_id' => $r->proof_file_id];
    }

    private function total(StipendRun $run): void
    {
        $run->forceFill(['total_cents' => (int) StipendLine::query()->where('run_id', $run->id)->where('status', 'pay')->sum('amount_cents')])->save();
    }

    /** Stop a spreadsheet treating a name as a formula. */
    private static function csvSafe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v;
    }
}
