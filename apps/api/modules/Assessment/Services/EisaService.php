<?php

declare(strict_types=1);

namespace Modules\Assessment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Assessment\Models\EisaCalendar;
use Modules\Assessment\Models\EisaDate;
use Modules\Assessment\Models\EisaRegistration;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;

/**
 * EISA registration: the calendar per qualification (the platform's, with the provider's own
 * dates and corrections), and each ready learner's registration with the assessment centre,
 * the foundational learning result and evidence, and the learner's declaration. No
 * registration after the closing date: the QCTO allows no late registrations.
 */
final class EisaService
{
    public const DECLARATION = 'I confirm that I have completed all the knowledge, practical skills and work experience modules of this qualification, '
        .'that the information in my registration is true, and that I will write the EISA on the date and at the assessment centre shown. '
        .'I understand that my details are shared with the QCTO and its quality partner for the assessment.';

    public const DECLARATION_VERSION = 'v1';

    /** FLC codes (column AO) that need a document: FLC certificate, school results, N3. */
    public const FLC_EVIDENCE = ['01', '03', '08'];

    public const FLC = [
        '01' => 'FLC certificate (competent)', '02' => 'RPL', '03' => 'Grade 12 / NCV Level 4 with Maths or Maths Literacy and English',
        '04' => 'Not yet competent', '05' => 'FLC not completed yet', '06' => 'Not applicable (NQF 5 and above)', '07' => 'Enrolled for FLC',
        '08' => 'N3 Mathematics and Business Language',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The EISA dates that apply to the provider for a qualification: the platform's calendar
     * with the provider's corrections, and the provider's own dates.
     *
     * @return list<array{key: string, eisa_on: string, closes_on: string, quality_partner: string|null, source: string|null, own: bool}>
     */
    public function dates(string $qualificationId, bool $pastToo = false): array
    {
        $own = EisaDate::query()->where('qualification_id', $qualificationId)->get();
        $replaced = $own->pluck('replaces_id')->filter()->all();
        $out = [];
        foreach (EisaCalendar::query()->where('qualification_id', $qualificationId)->get() as $c) {
            if (! in_array($c->id, $replaced, true)) {
                $out[] = ['key' => 'c:'.$c->id, 'eisa_on' => $c->eisa_on->toDateString(), 'closes_on' => $c->closes_on->toDateString(), 'quality_partner' => $c->quality_partner, 'source' => $c->source, 'own' => false];
            }
        }
        foreach ($own as $d) {
            if ($d->eisa_on !== null && $d->closes_on !== null) {
                $out[] = ['key' => 'p:'.$d->id, 'eisa_on' => $d->eisa_on->toDateString(), 'closes_on' => $d->closes_on->toDateString(), 'quality_partner' => null, 'source' => $d->source, 'own' => true];
            }
        }
        $today = CarbonImmutable::today()->toDateString();
        $out = array_values(array_filter($out, fn (array $d) => $pastToo || $d['eisa_on'] >= $today));
        usort($out, fn (array $a, array $b) => strcmp($a['eisa_on'], $b['eisa_on']));

        return $out;
    }

    /**
     * The next date still open for registration, if any.
     *
     * @return array{key: string, eisa_on: string, closes_on: string, quality_partner: string|null, source: string|null, own: bool}|null
     */
    public function nextOpen(string $qualificationId): ?array
    {
        $today = CarbonImmutable::today()->toDateString();
        foreach ($this->dates($qualificationId) as $d) {
            if ($d['closes_on'] >= $today) {
                return $d;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $input */
    public function addDate(User $by, array $input): EisaDate
    {
        $data = Validator::make($input, [
            'qualification_id' => ['required', 'ulid'],
            'replaces_id' => ['nullable', 'ulid'],
            'eisa_on' => ['required_without:replaces_id', 'nullable', 'date'],
            'closes_on' => ['required_with:eisa_on', 'nullable', 'date', 'before:eisa_on'],
            'source' => ['required', 'string', 'min:3', 'max:300'],
        ], ['source.required' => 'Say where the date comes from (a notice, an email from the quality partner…).'])->validate();
        if (isset($data['replaces_id']) && ! EisaCalendar::query()->whereKey($data['replaces_id'])->where('qualification_id', $data['qualification_id'])->exists()) {
            throw ValidationException::withMessages(['replaces_id' => 'Choose one of this qualification\'s dates.']);
        }
        $d = EisaDate::query()->create($data + ['created_by' => $by->id]);
        $this->audit->record('eisa.date_added', $d, [], ['source' => $d->source]);

        return $d;
    }

    /**
     * Who is ready and registered for the EISA in a cohort, and what is missing.
     *
     * @return array{dates: list<array<string, mixed>>, next: array<string, mixed>|null, days_to_close: int|null, learners: array<int, array<string, mixed>>}
     */
    public function cohort(Cohort $c): array
    {
        $enrolments = Enrolment::query()->where('cohort_id', $c->id)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $sors = StatementOfResults::query()->whereIn('enrolment_id', $enrolments->pluck('id'))->where('status', 'issued')->get()->keyBy('enrolment_id');
        $regs = EisaRegistration::query()->whereIn('enrolment_id', $enrolments->pluck('id'))->where('status', '!=', 'withdrawn')->get()->keyBy('enrolment_id');
        $next = $this->nextOpen($c->qualification_id);

        return ['dates' => $this->dates($c->qualification_id), 'next' => $next,
            'days_to_close' => $next ? (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($next['closes_on']), false) : null,
            'learners' => $enrolments->map(function (Enrolment $e) use ($sors, $regs) {
                $r = $regs->get($e->id);

                return ['enrolment_id' => $e->id, 'user_id' => $e->user_id, 'sor' => $sors->get($e->id)?->number,
                    'registration' => $r ? self::present($r) : null, 'missing' => $this->missing($r, $sors->has($e->id))];
            })->values()->all()];
    }

    /** @return list<string> */
    public function missing(?EisaRegistration $r, bool $sor): array
    {
        $m = [];
        if (! $sor) {
            $m[] = 'The Statement of Results is not issued yet.';
        }
        if ($r === null) {
            $m[] = 'Choose the EISA date and assessment centre.';

            return $m;
        }
        if ($r->assessment_centre === null) {
            $m[] = 'Choose the assessment centre.';
        }
        if ($r->flc_code === null) {
            $m[] = 'Record the foundational learning (FLC) result.';
        } elseif (in_array($r->flc_code, self::FLC_EVIDENCE, true) && $r->flc_file_id === null) {
            $m[] = 'Upload the FLC evidence.';
        }
        if ($r->declared_at === null) {
            $m[] = 'The learner has not signed the EISA declaration.';
        }
        if ($r->status !== 'registered' && $r->closes_on->isBefore(CarbonImmutable::today())) {
            $m[] = 'Registration for '.$r->eisa_on->format('j M Y').' closed on '.$r->closes_on->format('j M Y').'. Choose the next date.';
        }

        return $m;
    }

    /**
     * Create or update the learner's registration (not yet registered).
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Enrolment $e, User $by, array $input): EisaRegistration
    {
        $data = Validator::make($input, [
            'date' => ['required', 'string', 'max:40'],
            'assessment_centre' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9\/\-\s]+$/'],
            'assessment_centre_name' => ['nullable', 'string', 'max:200'],
            'flc_code' => ['nullable', 'in:'.implode(',', array_keys(self::FLC))],
            'flc_number' => ['nullable', 'string', 'max:60'],
            'flc_file_id' => ['nullable', 'ulid'],
        ])->validate();
        $c = Cohort::query()->whereKey($e->cohort_id)->firstOrFail();
        if (! StatementOfResults::query()->where('enrolment_id', $e->id)->where('status', 'issued')->exists()) {
            throw ValidationException::withMessages(['enrolment' => 'Issue the Statement of Results before registering the learner for the EISA.']);
        }
        $date = collect($this->dates($c->qualification_id))->firstWhere('key', $data['date'])
            ?? throw ValidationException::withMessages(['date' => 'Choose one of the qualification\'s EISA dates.']);
        if ($date['closes_on'] < CarbonImmutable::today()->toDateString()) {
            $next = $this->nextOpen($c->qualification_id);
            throw ValidationException::withMessages(['date' => 'Registration for that EISA has closed (no late registrations). '
                .($next ? 'The next is '.CarbonImmutable::parse($next['eisa_on'])->format('j M Y').', closing '.CarbonImmutable::parse($next['closes_on'])->format('j M Y').'.' : 'No later date is published yet.')]);
        }
        $flc = $data['flc_code'] ?? null;
        $number = isset($data['flc_number']) ? trim((string) $data['flc_number']) : null;
        if ($flc === '02' && ($number === null || ! preg_match('/^RPL\d{8}$/', $number))) {
            throw ValidationException::withMessages(['flc_number' => 'For RPL, write RPL and the assessment date (RPLYYYYMMDD).']);
        }
        if ($flc === '06') {
            $level = (int) Qualification::query()->whereKey($c->qualification_id)->value('nqf_level');
            if ($level < 5) {
                throw ValidationException::withMessages(['flc_code' => 'FLC is "not applicable" only for qualifications at NQF 5 and above.']);
            }
        }
        if (! empty($data['flc_file_id']) && ! File::query()->whereKey($data['flc_file_id'])->where('purpose', 'flc_evidence')->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['flc_file_id' => 'Upload the FLC evidence again.']);
        }
        $r = EisaRegistration::query()->where('enrolment_id', $e->id)->where('status', '!=', 'withdrawn')->first();
        if ($r?->status === 'registered') {
            throw ValidationException::withMessages(['enrolment' => 'The learner is registered. Withdraw the registration to change it.']);
        }
        $changedDate = $r !== null && $r->eisa_on->toDateString() !== $date['eisa_on'];
        $r ??= new EisaRegistration(['enrolment_id' => $e->id, 'user_id' => $e->user_id, 'cohort_id' => $c->id]);
        $r->fill(['eisa_on' => $date['eisa_on'], 'closes_on' => $date['closes_on'], 'assessment_centre' => isset($data['assessment_centre']) ? strtoupper(trim((string) $data['assessment_centre'])) : null,
            'assessment_centre_name' => $data['assessment_centre_name'] ?? null, 'flc_code' => $flc, 'flc_number' => $number ?: null, 'flc_file_id' => $data['flc_file_id'] ?? null]);
        if ($changedDate) {
            $r->forceFill(['declared_at' => null, 'declared_name' => null, 'declaration_version' => null]); // the learner declares for the date
        }
        $r->save();
        $this->audit->record('eisa.saved', $r, [], ['eisa_on' => $date['eisa_on']]);

        return $r;
    }

    /** @param  array<string, mixed>  $input */
    public function declare(EisaRegistration $r, User $learner, array $input): EisaRegistration
    {
        $data = Validator::make($input, ['confirm' => ['accepted'], 'name' => ['required', 'string', 'max:200']],
            ['confirm.accepted' => 'Tick the declaration to sign it.'])->validate();
        abort_unless($r->user_id === $learner->id, 404);
        if ($r->status !== 'draft') {
            throw ValidationException::withMessages(['confirm' => 'This registration can no longer be changed.']);
        }
        if (mb_strtolower(trim((string) $data['name'])) !== mb_strtolower($learner->fullName())) {
            throw ValidationException::withMessages(['name' => 'Type your full name as it is on your record: '.$learner->fullName().'.']);
        }
        $r->forceFill(['declared_at' => now(), 'declared_name' => trim((string) $data['name']), 'declaration_version' => self::DECLARATION_VERSION])->save();
        $this->audit->record('eisa.declared', $r);

        return $r;
    }

    public function register(EisaRegistration $r, User $by): EisaRegistration
    {
        if ($r->status !== 'draft') {
            throw ValidationException::withMessages(['registration' => 'The learner is already registered.']);
        }
        $missing = $this->missing($r, StatementOfResults::query()->where('enrolment_id', $r->enrolment_id)->where('status', 'issued')->exists());
        if ($missing !== []) {
            throw ValidationException::withMessages(['registration' => $missing]);
        }
        $r->forceFill(['status' => 'registered', 'registered_by' => $by->id, 'registered_at' => now()])->save();
        $this->audit->record('eisa.registered', $r, [], ['eisa_on' => $r->eisa_on->toDateString()]);
        app(ResultNotifier::class)->tell($r->user_id, 'Registered for the EISA', 'You are registered to write the EISA on '.$r->eisa_on->format('j F Y').'.', ['type' => 'eisa']);

        return $r;
    }

    /** @param  array<string, mixed>  $input */
    public function withdraw(EisaRegistration $r, array $input): EisaRegistration
    {
        Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        $r->forceFill(['status' => 'withdrawn'])->save();
        $this->audit->record('eisa.withdrawn', $r, [], ['reason' => (string) $input['reason']]);

        return $r;
    }

    /** @return array<string, mixed> */
    public static function present(EisaRegistration $r): array
    {
        return ['id' => $r->id, 'enrolment_id' => $r->enrolment_id, 'eisa_on' => $r->eisa_on->toDateString(), 'closes_on' => $r->closes_on->toDateString(),
            'assessment_centre' => $r->assessment_centre, 'assessment_centre_name' => $r->assessment_centre_name, 'flc_code' => $r->flc_code, 'flc_number' => $r->flc_number,
            'flc_file_id' => $r->flc_file_id, 'declared_at' => $r->declared_at?->toIso8601String(), 'status' => $r->status, 'registered_at' => $r->registered_at?->toIso8601String()];
    }
}
