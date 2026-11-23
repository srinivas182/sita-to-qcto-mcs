<?php

declare(strict_types=1);

namespace Modules\Assessment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Assessment\Http\Controllers\ModerationController;
use Modules\Assessment\Models\Appeal;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookSignoff;
use Modules\Logbook\Services\LogbookService;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;

/**
 * The Statement of Results: issued by the provider once every required module is final and
 * Competent (moderated if sampled, no open appeal) and every work experience module's logbook
 * is signed off and checked. It is a fixed snapshot with a number, a fingerprint and a public
 * verification code; mistakes are fixed by withdrawing it and issuing a new one.
 */
final class StatementService
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /**
     * What stands between the learner and their Statement of Results.
     *
     * @return array{ready: bool, blockers: list<string>, modules: list<array<string, mixed>>}
     */
    public function readiness(Enrolment $e, Cohort $c): array
    {
        return $this->readinessFor([$e], $c)[$e->id];
    }

    /**
     * readiness() for many enrolments of one cohort in a fixed number of queries.
     *
     * @param  iterable<Enrolment>  $enrolments
     * @return array<string, array{ready: bool, blockers: list<string>, modules: list<array<string, mixed>>}>
     */
    public function readinessFor(iterable $enrolments, Cohort $c): array
    {
        $list = [];
        foreach ($enrolments as $e) {
            $list[] = $e;
        }
        $ids = array_map(fn (Enrolment $e) => $e->id, $list);
        $all = app(AssessmentService::class)->modulesFor($list, $c->qualification_id);
        $groups = QualificationModule::query()->where('qualification_id', $c->qualification_id)->pluck('group', 'id')->all();
        $open = $ids === [] ? [] : Appeal::query()->where('assessment_appeals.status', 'open')
            ->join('assessment_results', 'assessment_results.id', '=', 'assessment_appeals.result_id')
            ->whereIn('assessment_results.enrolment_id', $ids)->pluck('assessment_results.enrolment_id')->flip()->all();
        $signoffs = $ids === [] ? collect() : LogbookSignoff::query()->whereIn('enrolment_id', $ids)->where('status', 'checked')->get(['enrolment_id', 'module_id'])
            ->groupBy('enrolment_id');
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = self::evaluate($all[$id], $groups, isset($open[$id]), ($signoffs->get($id) ?? collect())->pluck('module_id')->all());
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @param  array<string, mixed>  $groups
     * @param  list<mixed>  $signoffs
     * @return array{ready: bool, blockers: list<string>, modules: list<array<string, mixed>>}
     */
    private static function evaluate(array $modules, array $groups, bool $open, array $signoffs): array
    {
        $blockers = [];
        $counted = [];
        foreach ($modules as $m) {
            $elective = ($groups[$m['module_id']] ?? '') === 'elective';
            /** @var list<array{latest: array<string, mixed>|null}> $instruments */
            $instruments = $m['instruments'];
            $assessed = array_filter($instruments, fn (array $i) => $i['latest'] !== null) !== [];
            if ($elective && ! $assessed) {
                continue; // electives the learner didn't take
            }
            $counted[] = $m;
            if ($m['state'] === 'no_assessment') {
                $blockers[] = "{$m['code']}: no summative assessment is set.";
            } elseif ($m['state'] !== 'competent') {
                $blockers[] = "{$m['code']}: not yet competent in every assessment.";
            } elseif (array_filter($instruments, fn (array $i) => ($i['latest']['status'] ?? null) !== 'final') !== []) {
                $blockers[] = "{$m['code']}: waiting for moderation.";
            }
            if ($m['kind'] === 'WM' && ! in_array($m['module_id'], $signoffs, true)) {
                $blockers[] = "{$m['code']}: the workplace logbook is not signed off and checked.";
            }
        }
        if ($open) {
            $blockers[] = 'An appeal is still open.';
        }
        if ($counted === []) {
            $blockers[] = 'No modules loaded for the qualification.';
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'modules' => $counted];
    }

    /** @param  array<string, mixed>  $input */
    public function issue(Enrolment $e, User $by, array $input): StatementOfResults
    {
        Validator::make($input, ['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm the results are final and correct.'])->validate();
        $c = Cohort::query()->whereKey($e->cohort_id)->firstOrFail();
        $r = $this->readiness($e, $c);
        if (! $r['ready']) {
            throw ValidationException::withMessages(['enrolment' => $r['blockers']]);
        }

        $sor = DB::transaction(function () use ($e, $c, $by, $r): StatementOfResults {
            DB::table('enrolments')->where('id', $e->id)->lockForUpdate()->value('id');
            if (StatementOfResults::query()->where('enrolment_id', $e->id)->where('status', 'issued')->exists()) {
                throw ValidationException::withMessages(['enrolment' => 'A Statement of Results is already issued. Withdraw it first to issue a corrected one.']);
            }
            $tenantId = (string) $e->tenant_id;
            $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id'); // one number at a time
            $year = now()->year;
            $n = StatementOfResults::query()->where('number', 'like', "%-{$year}-%")->count() + 1;
            $snapshot = $this->snapshot($e, $c, $r['modules'], $tenant);
            $sor = new StatementOfResults(['enrolment_id' => $e->id, 'user_id' => $e->user_id, 'cohort_id' => $c->id,
                'number' => strtoupper((string) $tenant->code).'-SOR-'.$year.'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'issued_by' => $by->id, 'issued_at' => now()->startOfSecond(), 'snapshot' => $snapshot, 'verify_code' => strtolower(Str::random(10))]);
            $sor->hash = self::hashOf($sor);
            $sor->save();

            return $sor;
        });
        $this->audit->record('sor.issued', $sor, [], ['number' => $sor->number]);
        app(ResultNotifier::class)->tell($e->user_id, 'Statement of Results issued', "Your Statement of Results {$sor->number} has been issued. You can now be registered for the external assessment.", ['type' => 'result']);

        return $sor;
    }

    /** @param  array<string, mixed>  $input */
    public function withdraw(StatementOfResults $s, User $by, array $input): StatementOfResults
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        if ($s->status !== 'issued') {
            throw ValidationException::withMessages(['reason' => 'This statement has already been withdrawn.']);
        }
        $s->forceFill(['status' => 'withdrawn', 'withdrawn_reason' => $data['reason'], 'withdrawn_by' => $by->id, 'withdrawn_at' => now()])->save();
        $this->audit->record('sor.withdrawn', $s, [], ['number' => $s->number]);

        return $s;
    }

    /**
     * What the public verification page shows: enough to confirm it is genuine.
     *
     * @return array<string, mixed>
     */
    public function verification(string $code): array
    {
        $s = $this->context->runAsPlatform(fn () => StatementOfResults::query()->where('verify_code', strtolower($code))->firstOrFail());
        $snap = $s->snapshot;

        return ['number' => $s->number, 'status' => $s->status, 'issued_at' => $s->issued_at->toIso8601String(), 'withdrawn_at' => $s->withdrawn_at?->toIso8601String(),
            'provider' => $snap['provider']['name'] ?? null, 'learner' => $snap['learner']['name'] ?? null, 'id_number' => $snap['learner']['id_number'] ?? null,
            'qualification' => $snap['qualification']['title'] ?? null, 'saqa_id' => $snap['qualification']['saqa_id'] ?? null,
            'modules' => count($snap['modules'] ?? []), 'intact' => hash_equals($s->hash, self::hashOf($s))];
    }

    public function pdf(StatementOfResults $s): string
    {
        $html = view('assessment::statement-of-results', ['s' => $s, 'snap' => $s->snapshot,
            'verifyUrl' => 'https://'.($s->snapshot['provider']['code'] ?? 'www').'.'.config('platform.base_domain').'/sor/'.$s->verify_code])->render();
        $this->audit->record('sor.downloaded', $s);

        return ModerationController::pdfFrom($html);
    }

    public static function hashOf(StatementOfResults $s): string
    {
        return hash('sha256', 'sor1|'.$s->number.'|'.$s->enrolment_id.'|'.$s->issued_at->utc()->format('Y-m-d\TH:i:s\Z').'|'.json_encode(self::canonical($s->snapshot), JSON_THROW_ON_ERROR));
    }

    /** Keys sorted at every level: the database (jsonb) does not keep key order. */
    private static function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(self::canonical(...), $v);
    }

    /** @return array<string, mixed> */
    public static function present(StatementOfResults $s): array
    {
        return ['id' => $s->id, 'enrolment_id' => $s->enrolment_id, 'number' => $s->number, 'status' => $s->status, 'issued_at' => $s->issued_at->toIso8601String(),
            'withdrawn_at' => $s->withdrawn_at?->toIso8601String(), 'withdrawn_reason' => $s->withdrawn_reason, 'verify_code' => $s->verify_code];
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @return array<string, mixed>
     */
    private function snapshot(Enrolment $e, Cohort $c, array $modules, Tenant $tenant): array
    {
        $learner = $this->context->runAsPlatform(fn () => User::query()->whereKey($e->user_id)->firstOrFail());
        $q = $this->context->runAsPlatform(fn () => Qualification::query()->whereKey($c->qualification_id)->firstOrFail());
        $centre = Centre::query()->whereKey($c->centre_id)->first();
        $placements = LearnerPlacement::query()->where('enrolment_id', $e->id)->orderBy('starts_on')->get();
        $sites = EmployerSite::query()->whereIn('id', $placements->pluck('site_id'))->get()->keyBy('id');
        $employers = Employer::query()->whereIn('id', $sites->pluck('employer_id'))->pluck('legal_name', 'id');
        $mentors = Mentor::query()->whereIn('id', $placements->pluck('mentor_id')->filter())->pluck('name', 'id');
        $hours = collect(app(LogbookService::class)->hours($e->user_id, $c->qualification_id))->keyBy('module_id');
        $date = function (array $m): ?string {
            /** @var list<array{latest: array<string, mixed>|null}> $instruments */
            $instruments = $m['instruments'];
            $dates = array_filter(array_map(fn (array $i) => isset($i['latest']['assessed_on']) ? (string) $i['latest']['assessed_on'] : null, $instruments));

            return $dates === [] ? null : max($dates);
        };

        return [
            'provider' => ['name' => $tenant->legal_name ?: $tenant->trading_name, 'code' => $tenant->code, 'centre' => $centre?->name, 'accreditation' => $centre?->accreditation_number],
            'learner' => ['name' => $learner->fullName(), 'id_number' => $learner->maskedIdNumber()],
            'qualification' => ['title' => $q->title, 'saqa_id' => $q->saqa_id, 'nqf_level' => $q->nqf_level, 'credits' => $q->credits],
            'cohort' => ['name' => $c->name, 'started' => $e->enrolled_on->toDateString()],
            'modules' => array_map(fn (array $m) => ['code' => $m['code'], 'title' => $m['title'], 'kind' => $m['kind'], 'credits' => $m['credits'], 'outcome' => 'Competent',
                'date' => $date($m), 'hours' => $m['kind'] === 'WM' ? ($hours->get($m['module_id'])['signed_hours'] ?? null) : null], $modules),
            'work_experience' => $placements->map(fn (LearnerPlacement $p) => ['employer' => $employers->get((string) $sites->get($p->site_id)?->employer_id),
                'site' => $sites->get($p->site_id)?->name, 'approval_number' => $sites->get($p->site_id)?->approval_number, 'mentor' => $mentors->get((string) $p->mentor_id),
                'from' => $p->starts_on->toDateString(), 'to' => $p->ends_on?->toDateString()])->values()->all(),
        ];
    }
}
