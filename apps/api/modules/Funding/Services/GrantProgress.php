<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

use Modules\Agreements\Models\Agreement;
use Modules\Assessment\Models\EisaRegistration;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\RegisterSignature;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\QctoOutcome;
use Modules\Enrolment\Models\QctoSubmission;
use Modules\Funding\Models\Funder;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantLearner;
use Modules\Funding\Models\GrantTranche;
use Modules\Identity\Models\User;
use Modules\Logbook\Models\LogbookSignoff;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;

/**
 * Which learners meet each tranche's milestones, from the platform's own records, with the
 * reason in plain words when they don't. One fixed set of queries per agreement.
 */
final class GrantProgress
{
    /** @return array<string, mixed> */
    public function of(GrantAgreement $a): array
    {
        $cohort = Cohort::query()->whereKey($a->cohort_id)->firstOrFail();
        $learners = GrantLearner::query()->where('agreement_id', $a->id)->get();
        $tranches = GrantTranche::query()->where('agreement_id', $a->id)->orderBy('number')->get();
        $enrolments = Enrolment::query()->whereIn('id', $learners->pluck('enrolment_id'))->get()->keyBy('id');
        $facts = $this->facts($cohort, array_values($enrolments->all()));
        $people = app(TenantContext::class)->runAsPlatform(fn () => User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id'));
        $funder = Funder::query()->whereKey($a->funder_id)->first();

        $rows = [];
        $met = array_fill_keys($tranches->pluck('id')->all(), 0);
        foreach ($learners as $gl) {
            $e = $enrolments->get($gl->enrolment_id);
            if ($e === null) {
                continue;
            }
            $f = $facts[$e->id];
            $per = [];
            foreach ($tranches as $t) {
                $blockers = $gl->status === 'active' ? self::blockers($t->milestones, $f) : ['Taken off the agreement.'];
                $per[$t->id] = ['met' => $blockers === [], 'blockers' => $blockers];
                $met[$t->id] += $blockers === [] ? 1 : 0;
            }
            $rows[] = ['grant_learner_id' => $gl->id, 'enrolment_id' => $e->id, 'user_id' => $e->user_id, 'name' => $people->get($e->user_id)?->fullName(),
                'status' => $gl->status, 'disabled' => $gl->disabled, 'removed_reason' => $gl->removed_reason, 'registers' => $f['registers'], 'tranches' => $per];
        }
        usort($rows, fn (array $x, array $y) => strcmp((string) $x['name'], (string) $y['name']));
        $active = $learners->where('status', 'active')->count();

        return [
            'agreement' => GrantService::present($a) + ['funder' => $funder?->present(), 'active_learners' => $active],
            'tranches' => $tranches->map(function (GrantTranche $t) use ($a, $met, $active): array {
                $perLearner = (int) round($a->amount_per_learner_cents * (float) $t->share_pct / 100);
                $missing = count(array_filter($t->evidence, fn (array $e) => empty($e['file_id'])));

                return ['id' => $t->id, 'number' => $t->number, 'share_pct' => (float) $t->share_pct, 'due_on' => $t->due_on?->toDateString(),
                    'milestones' => array_map(fn (array $m) => $m + ['label' => Milestones::label($m)], $t->milestones), 'evidence' => $t->evidence,
                    'evidence_missing' => $missing, 'per_learner_cents' => $perLearner, 'learners_met' => $met[$t->id], 'learners_total' => $active,
                    'claimable_cents' => $perLearner * $met[$t->id], 'ready' => $met[$t->id] > 0 && $missing === 0];
            })->values()->all(),
            'learners' => $rows,
        ];
    }

    /**
     * @param  list<array{key: string, months?: int}>  $milestones
     * @param  array{enrolled: bool, agreement: string|null, qcto: string|null, registers: int, logbook: list<string>, sor: bool, eisa: bool}  $f
     * @return list<string>
     */
    public static function blockers(array $milestones, array $f): array
    {
        $out = [];
        foreach ($milestones as $m) {
            switch ($m['key']) {
                case 'enrolled':
                    if (! $f['enrolled']) {
                        $out[] = 'Not enrolled (withdrawn or not started).';
                    } elseif ($f['agreement'] !== null) {
                        $out[] = $f['agreement'];
                    }
                    break;
                case 'qcto_submitted':
                    if ($f['qcto'] !== null) {
                        $out[] = $f['qcto'];
                    }
                    break;
                case 'registers':
                    $need = (int) ($m['months'] ?? 1);
                    if ($f['registers'] < $need) {
                        $out[] = "{$f['registers']} of {$need} monthly registers signed.";
                    }
                    break;
                case 'logbook':
                    foreach ($f['logbook'] as $code) {
                        $out[] = "{$code}: logbook not signed off and checked.";
                    }
                    break;
                case 'sor':
                    if (! $f['sor']) {
                        $out[] = 'No Statement of Results issued.';
                    }
                    break;
                case 'eisa':
                    if (! $f['eisa']) {
                        $out[] = 'Not registered for the EISA.';
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * @param  list<Enrolment>  $enrolments
     * @return array<string, array{enrolled: bool, agreement: string|null, qcto: string|null, registers: int, logbook: list<string>, sor: bool, eisa: bool}>
     */
    private function facts(Cohort $cohort, array $enrolments): array
    {
        $ids = array_map(fn (Enrolment $e) => $e->id, $enrolments);
        $users = array_map(fn (Enrolment $e) => $e->user_id, $enrolments);
        if ($ids === []) {
            return [];
        }
        $agreements = Agreement::query()->whereIn('id', array_filter(array_map(fn (Enrolment $e) => $e->agreement_id, $enrolments)))->pluck('status', 'id');

        // QCTO: the latest submission that holds the learner decides.
        $subs = QctoSubmission::query()->where('cohort_id', $cohort->id)->orderBy('sent_on')->orderBy('created_at')->get();
        $latest = [];
        foreach ($subs as $s) {
            foreach ($s->learner_ids as $uid) {
                $latest[$uid] = $s->id;
            }
        }
        $rejected = QctoOutcome::query()->whereIn('submission_id', $subs->pluck('id'))->where('status', 'rejected')->whereNull('resolved_at')->get()
            ->mapWithKeys(fn (QctoOutcome $o) => [$o->submission_id.'|'.$o->user_id => $o->reason ?? 'rejected']);

        $registers = AttendanceRegister::query()->where('cohort_id', $cohort->id)->whereIn('status', ['complete', 'submitted'])->pluck('id');
        $signed = RegisterSignature::query()->whereIn('register_id', $registers)->where('role', 'learner')->where('status', 'signed')
            ->whereIn('user_id', $users)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        $wm = QualificationModule::query()->where('qualification_id', $cohort->qualification_id)->where('kind', 'WM')->where('group', '!=', 'elective')->pluck('code', 'id');
        $signoffs = LogbookSignoff::query()->whereIn('enrolment_id', $ids)->where('status', 'checked')->get(['enrolment_id', 'module_id'])->groupBy('enrolment_id');
        $sor = StatementOfResults::query()->whereIn('enrolment_id', $ids)->where('status', 'issued')->pluck('enrolment_id')->flip();
        $eisa = EisaRegistration::query()->whereIn('enrolment_id', $ids)->where('status', 'registered')->pluck('enrolment_id')->flip();

        $out = [];
        foreach ($enrolments as $e) {
            $agreement = null;
            if ($e->agreement_id === null) {
                $agreement = 'No learner agreement on record.';
            } elseif (($agreements[$e->agreement_id] ?? null) !== 'completed') {
                $agreement = 'Learner agreement not signed by everyone yet.';
            }
            $sub = $latest[$e->user_id] ?? null;
            $qcto = $sub === null ? 'Not on a QCTO enrolment submission yet.'
                : ($rejected->has($sub.'|'.$e->user_id) ? 'Rejected by the QCTO: '.$rejected->get($sub.'|'.$e->user_id) : null);
            $done = ($signoffs->get($e->id) ?? collect())->pluck('module_id')->all();
            $out[$e->id] = [
                'enrolled' => in_array($e->status, ['active', 'suspended', 'completed'], true),
                'agreement' => $agreement,
                'qcto' => $qcto,
                'registers' => (int) ($signed[$e->user_id] ?? 0),
                'logbook' => array_values(array_map('strval', $wm->filter(fn ($code, $id) => ! in_array($id, $done, true))->all())),
                'sor' => $sor->has($e->id),
                'eisa' => $eisa->has($e->id),
            ];
        }

        return $out;
    }
}
