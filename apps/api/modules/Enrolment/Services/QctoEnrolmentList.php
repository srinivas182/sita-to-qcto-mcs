<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\EnrolmentSettings;
use Modules\Enrolment\Models\QctoSubmission;
use Modules\Enrolment\Qcto\QctoRules;
use Modules\Enrolment\Support\WorkingDays;
use Modules\Identity\Models\Consent;
use Modules\Identity\Models\User;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\SaIdNumber;
use Modules\People\Models\PersonProfile;
use Modules\Qualifications\Models\CentreQualification;
use Modules\Qualifications\Models\Qualification;
use Modules\Support\Qcto\QctoCodes;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The QCTO learner enrolment file for a cohort (Learner Enrolment and Readiness for EISA,
 * columns A to AJ and the date stamp in AQ), each learner's missing or doubtful fields,
 * and the countdown to the due date (21 days from the start of training by default).
 */
final class QctoEnrolmentList
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** @return array{due_on: string, days_left: int, working_days: bool, days: int} */
    public function due(Cohort $cohort): array
    {
        $s = EnrolmentSettings::current();
        $start = CarbonImmutable::parse($cohort->intake_on)->startOfDay();
        $due = $s->qcto_working_days ? WorkingDays::add($start, $s->qcto_days) : $start->addDays($s->qcto_days);

        return ['due_on' => $due->toDateString(), 'days_left' => (int) CarbonImmutable::today()->diffInDays($due, false),
            'working_days' => $s->qcto_working_days, 'days' => $s->qcto_days];
    }

    /**
     * @return array{cohort: array<string, mixed>, due: array<string, mixed>, rows: list<array<string, mixed>>, ready: int, total: int,
     *               sent: list<array<string, mixed>>, unsent: int}
     */
    public function build(Cohort $cohort, ?string $onlyUser = null): array
    {
        $qualification = Qualification::query()->whereKey($cohort->qualification_id)->firstOrFail();
        $centre = Centre::query()->whereKey($cohort->centre_id)->firstOrFail();
        $enrolments = Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('status', ['active', 'suspended', 'completed'])
            ->when($onlyUser !== null, fn ($q) => $q->where('user_id', $onlyUser))->orderBy('enrolled_on')->get();
        $userIds = $enrolments->pluck('user_id')->all();
        /** @var Collection<string, User> $users */
        $users = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $userIds)->get()->keyBy('id'));
        $profiles = PersonProfile::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $consents = Consent::query()->whereIn('user_id', $userIds)->where('purpose', 'data_sharing_seta')->whereNull('withdrawn_at')
            ->orderBy('granted_at')->get()->keyBy('user_id');
        $sent = QctoSubmission::query()->where('cohort_id', $cohort->id)->orderBy('sent_on')->get();
        $sentIds = $sent->flatMap(fn (QctoSubmission $s) => $s->learner_ids)->unique()->all();

        $scope = CentreQualification::query()->where('centre_id', $centre->id)->where('qualification_id', $qualification->id)->first();
        $ctx = ['intake_on' => $cohort->intake_on->toDateString(), 'planned_end_on' => $cohort->planned_end_on->toDateString(),
            'centre_expires_on' => $centre->accreditation_expires_on?->toDateString(),
            'in_scope' => $scope !== null || in_array($qualification->saqa_id, $centre->scope ?? [], true) ? true
                : (CentreQualification::query()->where('centre_id', $centre->id)->exists() || ($centre->scope ?? []) !== [] ? false : null),
            'scope_expires_on' => $scope?->expires_on?->toDateString(), 'eisa_on' => $this->eisaOn($cohort)];
        $rows = [];
        foreach ($enrolments as $e) {
            $user = $users->get($e->user_id);
            if ($user === null) {
                continue;
            }
            $row = $this->row($user, $profiles->get($e->user_id), $consents->get($e->user_id), $cohort, $qualification, $centre, $e);
            $row = self::withRules($row, $ctx);
            $rows[] = ['enrolment_id' => $e->id, 'user_id' => $user->id, 'name' => $user->fullName(), 'enrolled_on' => $e->enrolled_on->toDateString(),
                'sent' => in_array($user->id, $sentIds, true)] + $row;
        }

        return [
            'cohort' => ['id' => $cohort->id, 'name' => $cohort->name, 'starts_on' => $cohort->intake_on->toDateString(), 'qualification' => $qualification->title,
                'saqa_id' => $qualification->saqa_id, 'centre' => $centre->name, 'sdp_code' => $centre->accreditation_number],
            'due' => $this->due($cohort),
            'rows' => $rows,
            'ready' => count(array_filter($rows, fn (array $r) => $r['errors'] === [])),
            'rules' => ['version' => QctoRules::VERSION, 'source' => QctoRules::SOURCE],
            'errors' => array_sum(array_map(fn (array $r) => count($r['errors']), $rows)),
            'warnings' => array_sum(array_map(fn (array $r) => count($r['warnings']), $rows)),
            'total' => count($rows),
            'sent' => array_values($sent->map(fn (QctoSubmission $s) => ['id' => $s->id, 'sent_on' => $s->sent_on->toDateString(), 'rows' => $s->rows, 'note' => $s->note])->all()),
            'unsent' => count(array_filter($rows, fn (array $r) => ! $r['sent'])),
        ];
    }

    /**
     * The row's own findings (missing values) with codes and fix targets, plus every rule of
     * the specification. Errors stop the file; warnings must be acknowledged.
     *
     * @param  array{columns: array<string, string>, errors: list<array<string, string>>, warnings: list<array<string, string>>}  $row
     * @param  array{intake_on: string, planned_end_on: string, centre_expires_on: string|null, in_scope: bool|null, scope_expires_on: string|null, eisa_on: string|null}  $ctx
     * @return array{columns: array<string, string>, errors: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public static function withRules(array $row, array $ctx): array
    {
        $tag = fn (array $f, string $sev) => ['code' => 'Q-REQUIRED', 'severity' => $sev, 'fix' => QctoRules::FIX[$f['column']] ?? 'profile'] + $f;
        $errors = array_map(fn (array $f) => $tag($f, 'error'), $row['errors']);
        $warnings = array_map(fn (array $f) => $tag($f, 'warning'), $row['warnings']);
        foreach (QctoRules::check($row['columns'], $ctx, array_column($row['errors'], 'column')) as $f) {
            $f['severity'] === 'error' ? $errors[] = $f : $warnings[] = $f;
        }

        return ['columns' => $row['columns'], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * What a learner can fix in their own details for the QCTO file, per course: shown in the
     * profile forms as they are saved.
     *
     * @return list<array{cohort: string, errors: list<array<string, string>>, warnings: list<array<string, string>>}>
     */
    public function forUser(User $user): array
    {
        $out = [];
        foreach (Enrolment::query()->where('user_id', $user->id)->whereIn('status', ['active', 'suspended'])->get() as $e) {
            $cohort = Cohort::query()->whereKey($e->cohort_id)->first();
            $row = $cohort ? ($this->build($cohort, $user->id)['rows'][0] ?? null) : null;
            if ($row === null) {
                continue;
            }
            $mine = fn (array $f) => in_array($f['fix'], ['profile', 'identity', 'consent'], true);
            $out[] = ['cohort' => $cohort->name, 'errors' => array_values(array_filter($row['errors'], $mine)), 'warnings' => array_values(array_filter($row['warnings'], $mine))];
        }

        return $out;
    }

    /** The EISA date the cohort is registered for, once known (Sprint 8c). */
    private function eisaOn(Cohort $cohort): ?string
    {
        $v = $cohort->getAttribute('eisa_on');

        return is_string($v) ? $v : null;
    }

    /**
     * The workbook as QCTO asks: Excel, every value as text, columns in place.
     *
     * @return array{name: string, bytes: string, rows: int}
     */
    public function workbook(Cohort $cohort, bool $onlyUnsent = false, bool $acknowledged = false): array
    {
        $data = $this->build($cohort);
        $rows = array_values(array_filter($data['rows'], fn (array $r) => ! $onlyUnsent || ! $r['sent']));
        self::assertClean($rows, $acknowledged);
        $tenantId = $this->context->tenantId();
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $name = 'LEISA'.now()->format('Ymd').'-'.Str::of($tenant->legal_name ?: $tenant->trading_name)->replaceMatches('/[^A-Za-z0-9 ()&.-]/', '')->trim().'.xlsx';

        $path = tempnam(sys_get_temp_dir(), 'leisa');
        $writer = new Writer;
        $writer->openToFile((string) $path);
        $writer->getCurrentSheet()->setName('Sheet2');
        $writer->addRow(Row::fromValues(array_values(QctoCodes::COLUMNS)));
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues(array_map(fn (string $col) => (string) ($r['columns'][$col] ?? ''), array_keys(QctoCodes::COLUMNS))));
        }
        $writer->close();
        $bytes = (string) file_get_contents((string) $path);
        @unlink((string) $path);
        $this->audit->record('qcto.enrolment_file', $cohort, [], ['rows' => count($rows), 'only_unsent' => $onlyUnsent, 'warnings_acknowledged' => $acknowledged, 'rules' => QctoRules::VERSION]);

        return ['name' => $name, 'bytes' => $bytes, 'rows' => count($rows)];
    }

    /**
     * Zero rejection: no file leaves with an error; warnings only once acknowledged.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function assertClean(array $rows, bool $acknowledged): void
    {
        $bad = array_filter($rows, fn (array $r) => $r['errors'] !== []);
        if ($bad !== []) {
            $n = count($bad);
            throw ValidationException::withMessages(['file' => $n === 1 ? '1 learner has errors the QCTO would reject. Fix them first.' : "{$n} learners have errors the QCTO would reject. Fix them first."]);
        }
        if (! $acknowledged && array_filter($rows, fn (array $r) => $r['warnings'] !== []) !== []) {
            throw ValidationException::withMessages(['acknowledge' => 'Some learners have warnings. Check them and confirm before exporting.']);
        }
    }

    /** Record that the file went to learnerenrolments@qcto.org.za, with the learners it held. */
    public function markSent(Cohort $cohort, string $sentOn, ?string $note, User $by, bool $onlyUnsent = true): QctoSubmission
    {
        $rows = array_values(array_filter($this->build($cohort)['rows'], fn (array $r) => ! $onlyUnsent || ! $r['sent']));
        self::assertClean($rows, true);
        $s = QctoSubmission::query()->create(['cohort_id' => $cohort->id, 'stage' => 'enrolment', 'learner_ids' => array_column($rows, 'user_id'),
            'rows' => count($rows), 'sent_on' => $sentOn, 'note' => $note, 'submitted_by' => $by->id]);
        $cohort->forceFill(['qcto_alerted_for' => null])->saveQuietly();
        $this->audit->record('qcto.enrolment_sent', $cohort, [], ['rows' => count($rows), 'sent_on' => $sentOn]);

        return $s;
    }

    /** @return array{columns: array<string, string>, errors: list<array{column: string, field: string, problem: string}>, warnings: list<array{column: string, field: string, problem: string}>} */
    private function row(User $user, ?PersonProfile $p, ?Consent $consent, Cohort $cohort, Qualification $q, Centre $centre, ?Enrolment $enrolment = null): array
    {
        $errors = [];
        $warnings = [];
        $need = function (string $col, ?string $value, string $problem) use (&$errors): string {
            if ($value === null || trim($value) === '') {
                $errors[] = ['column' => $col, 'field' => QctoCodes::COLUMNS[$col], 'problem' => $problem];

                return '';
            }

            return trim($value);
        };
        $warn = function (string $col, string $problem) use (&$warnings): void {
            $warnings[] = ['column' => $col, 'field' => QctoCodes::COLUMNS[$col], 'problem' => $problem];
        };
        $digits = fn (?string $v): ?string => $v === null ? null : ((string) preg_replace('/\D/', '', $v) ?: null);

        $sa = $user->id_type === IdType::SaId && $user->id_number !== null ? SaIdNumber::tryParse((string) $user->id_number) : null;
        $equity = $p?->equity_consented_at !== null ? ($p->equity ?? []) : [];
        $c = [];
        $c['A'] = $need('A', $centre->accreditation_number, "Add the centre's QCTO SDP code or accreditation number.");
        $c['B'] = $need('B', $q->saqa_id, 'The qualification has no SAQA ID.');
        if ($sa !== null) {
            $c['C'] = $sa->value;
            $c['D'] = '';
            $c['E'] = '533';
        } else {
            $c['C'] = '';
            $c['D'] = $need('D', $user->id_number !== null ? (string) $user->id_number : null, 'Record a South African ID or passport number.');
            $c['E'] = $user->id_type === IdType::Passport ? '527' : '';
            if ($c['E'] === '') {
                $errors[] = ['column' => 'E', 'field' => QctoCodes::COLUMNS['E'], 'problem' => 'Record the kind of identity document.'];
            }
        }
        $race = $equity['race'] ?? null;
        $c['F'] = QctoCodes::EQUITY[$race] ?? 'U';
        if (! isset(QctoCodes::EQUITY[$race])) {
            $warn('F', 'Race not given (optional for the learner), reported as Unknown.');
        }
        $citizen = $p->citizen_status ?? ($sa !== null ? ($sa->citizenship === 0 ? 'SA' : 'PR') : null);
        $c['G'] = $need('G', $p->nationality ?? ($sa?->citizenship === 0 ? 'SA' : null), 'Record the nationality.');
        $c['H'] = $need('H', QctoCodes::language($p?->home_language), 'Record the home language.');
        $sex = $sa?->sexOnIssue();
        $c['I'] = $sex !== null ? ($sex === 'male' ? 'M' : 'F') : $need('I', match ($equity['gender'] ?? null) {
            'male' => 'M', 'female' => 'F', default => null
        }, 'Record the gender as on the identity document.');
        $c['J'] = $need('J', $citizen, 'Record citizen or resident status.');
        $c['K'] = $p->socioeconomic_status ?? 'U';
        if ($p?->socioeconomic_status === null) {
            $warn('K', 'Work status not recorded, reported as Unspecified.');
        }
        $disabled = $equity['disability'] ?? null;
        $c['L'] = $disabled === true ? $need('L', $equity['disability_code'] ?? null, 'Record the kind of disability.') : 'N';
        if ($disabled === null) {
            $warn('L', 'Disability not given (optional for the learner), reported as None.');
        }
        $c['M'] = $disabled === true ? $need('M', $equity['disability_rating'] ?? null, 'Record the disability rating.') : '';
        $c['N'] = match ($citizen) {
            'SA', 'D' => '03', null => '', default => $need('N', null, 'Record whether the learner is an immigrant or a refugee.'),
        };
        $c['O'] = $need('O', $user->last_name, 'Record the last name.');
        $c['P'] = $need('P', $user->first_name, 'Record the first name.');
        $c['Q'] = (string) $p?->middle_name;
        $c['R'] = $need('R', $p?->title, 'Record the title (Mr, Ms, …).');
        $c['S'] = $need('S', $user->dob?->format('Ymd'), 'Record the date of birth.');

        $line1 = $p?->address_line1;
        if ($line1 !== null && ! preg_match('/\d+\s*\D{3,}/', $line1)) {
            $warn('T', 'The first address line should have a street number and name.');
        }
        $c['T'] = $need('T', $line1, 'Record the home address.');
        $c['U'] = $need('U', $p?->address_line2 ?: $p?->city, 'Record the suburb or town.');
        $c['V'] = $p?->address_line2 ? (string) $p->city : '';
        $postalSame = $p->postal_same ?? true;
        $c['W'] = $postalSame ? $c['T'] : $need('W', $p?->postal_line1, 'Record the postal address.');
        $c['X'] = $postalSame ? $c['U'] : $need('X', $p?->postal_line2, 'Record the postal address.');
        $c['Y'] = $postalSame ? $c['V'] : '';
        $c['Z'] = $need('Z', $p?->postal_code, 'Record the home postal code.');
        $c['AA'] = $postalSame ? $c['Z'] : $need('AA', $p?->postal_code_postal, 'Record the postal code of the postal address.');
        $c['AB'] = '';
        $c['AC'] = $user->phone_e164 !== null ? '0'.substr((string) $digits($user->phone_e164), 2) : '';
        $c['AD'] = '';
        $c['AE'] = (string) $user->email;
        $c['AF'] = $need('AF', QctoCodes::PROVINCES[$p->province ?? ''] ?? null, 'Record the province.');
        $c['AG'] = $need('AG', $p?->statssa_area_code, 'Record the Stats SA area code for the home address.');
        $c['AH'] = $consent !== null ? 'Yes' : 'No';
        $c['AI'] = $consent?->granted_at->format('Ymd') ?? '';
        if ($consent === null) {
            $errors[] = ['column' => 'AH', 'field' => QctoCodes::COLUMNS['AH'], 'problem' => 'No POPIA consent on record.'];
        }
        $c['AJ'] = $cohort->planned_end_on->format('Ymd');
        // Columns AK to AP are the second submission (readiness for the EISA). The Statement of
        // Results fills AK and AL; AN becomes "SDP training and assessment for readiness" (4).
        $sorAt = $enrolment !== null ? StatementOfResults::query()->where('enrolment_id', $enrolment->id)->where('status', 'issued')->value('issued_at') : null;
        $c['AK'] = $sorAt !== null ? '01' : '02';
        $c['AL'] = $sorAt !== null ? CarbonImmutable::parse((string) $sorAt)->setTimezone('Africa/Johannesburg')->format('Ymd') : '';
        $c['AN'] = $sorAt !== null ? '4' : '1';
        $c['AQ'] = now()->format('Ymd');

        return ['columns' => $c, 'errors' => $errors, 'warnings' => $warnings];
    }
}
