<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

use Illuminate\Support\Carbon;
use Modules\Access\Services\AccessService;
use Modules\Assessment\Services\EisaService;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Enrolment\Models\QctoOutcome;
use Modules\Enrolment\Models\QctoSubmission;
use Modules\Funding\Models\ClaimQuery;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantClaim;
use Modules\Funding\Models\StipendLine;
use Modules\Funding\Models\StipendRun;
use Modules\Identity\Models\User;

/**
 * One list of what is stuck and where to fix it: QCTO rejections, funder queries, money ready
 * to claim, stipends to confirm or pay, registers to send, EISA closing dates. Each item links to
 * the screen where it is fixed. Only cohorts the person may see are counted.
 */
final class FixInbox
{
    /** @return array{items: list<array<string, mixed>>, funding: array<string, int>|null} */
    public function for(User $u): array
    {
        $access = app(AccessService::class);
        $centres = $access->centresFor($u, 'cohorts.view');
        $cohorts = Cohort::query()->whereIn('status', ['draft', 'open'])->when($centres !== null, fn ($q) => $q->whereIn('centre_id', $centres ?? []))->get()->keyBy('id');
        $items = [];
        $can = fn (string $p) => $u->can($p);

        if ($can('cohorts.view') && $cohorts->isNotEmpty()) {
            $subs = QctoSubmission::query()->whereIn('cohort_id', $cohorts->keys())->pluck('cohort_id', 'id');
            $rejected = [];
            foreach (QctoOutcome::query()->whereIn('submission_id', $subs->keys())->where('status', 'rejected')->whereNull('resolved_at')->get() as $o) {
                $cid = (string) $subs[$o->submission_id];
                $rejected[$cid] = ($rejected[$cid] ?? 0) + 1;
            }
            foreach ($rejected as $cohortId => $n) {
                $items[] = self::item('danger', 'qcto', "{$n} learner(s) rejected by the QCTO", (string) $cohorts[$cohortId]?->name,
                    ['to' => '/cohorts/$id/qcto', 'params' => ['id' => $cohortId]]);
            }
            $registers = AttendanceRegister::query()->whereIn('cohort_id', $cohorts->keys())->where('status', 'complete')->get();
            foreach ($registers as $r) {
                $items[] = self::item('warning', 'register', 'Register for '.$r->month->format('F Y').' is signed but not sent to the SETA', (string) $cohorts[$r->cohort_id]?->name,
                    ['to' => '/cohorts/$id/registers', 'params' => ['id' => $r->cohort_id]]);
            }
            $eisa = app(EisaService::class);
            foreach ($cohorts as $c) {
                $d = $eisa->cohort($c);
                $waiting = count(array_filter($d['learners'], fn (array $l) => $l['sor'] !== null && ($l['registration']['status'] ?? null) !== 'registered'));
                if ($waiting > 0 && $d['days_to_close'] !== null && $d['days_to_close'] <= 30) {
                    $items[] = self::item($d['days_to_close'] <= 14 ? 'danger' : 'warning', 'eisa',
                        "EISA registration closes in {$d['days_to_close']} days: {$waiting} learner(s) not registered", $c->name,
                        ['to' => '/cohorts/$id/eisa', 'params' => ['id' => $c->id]]);
                }
            }
        }

        $funding = null;
        $fCentres = $access->centresFor($u, 'funding.view');
        if ($can('funding.view')) {
            $fCohorts = Cohort::query()->when($fCentres !== null, fn ($q) => $q->whereIn('centre_id', $fCentres ?? []))->pluck('name', 'id');
            $agreements = GrantAgreement::query()->whereIn('cohort_id', $fCohorts->keys())->where('status', 'active')->get();
            $claims = GrantClaim::query()->whereIn('agreement_id', $agreements->pluck('id'))->get();
            foreach (ClaimQuery::query()->whereIn('claim_id', $claims->pluck('id'))->whereNull('resolved_at')->get()->groupBy('claim_id') as $claimId => $qs) {
                $claim = $claims->firstWhere('id', $claimId);
                $items[] = self::item('danger', 'claim_query', "{$qs->count()} open query(ies) on claim {$claim?->number}", (string) $qs->first()?->problem,
                    ['to' => '/grant-claims/$id', 'params' => ['id' => $claimId]]);
            }
            $claimable = 0;
            $progress = app(GrantProgress::class);
            $claimsService = app(ClaimService::class);
            foreach ($agreements as $a) {
                $p = $progress->of($a);
                foreach ($p['tranches'] as $t) {
                    $unclaimed = 0;
                    $claimed = $claimsService->claimedFor($t['id']);
                    foreach ($p['learners'] as $l) {
                        $unclaimed += ($l['status'] === 'active' && $l['tranches'][$t['id']]['met'] && ! in_array($l['enrolment_id'], $claimed, true)) ? 1 : 0;
                    }
                    if ($unclaimed === 0) {
                        continue;
                    }
                    if ($t['evidence_missing'] > 0) {
                        $items[] = self::item('warning', 'evidence', "Tranche {$t['number']}: {$t['evidence_missing']} evidence item(s) to upload before claiming",
                            "{$a->reference} · {$unclaimed} learner(s) ready", ['to' => '/grant-agreements/$id', 'params' => ['id' => $a->id]]);
                    } else {
                        $claimable += $unclaimed * $t['per_learner_cents'];
                        $items[] = self::item('info', 'claim', "Tranche {$t['number']} can be claimed for {$unclaimed} learner(s)", $a->reference,
                            ['to' => '/grant-agreements/$id', 'params' => ['id' => $a->id]]);
                    }
                }
            }
            $runs = StipendRun::query()->whereIn('agreement_id', $agreements->pluck('id'))->whereIn('status', ['draft', 'confirmed'])->get();
            foreach ($runs as $r) {
                $month = $r->month->format('F Y');
                $items[] = $r->status === 'draft'
                    ? self::item('warning', 'stipend', "Stipends for {$month} to check and confirm", (string) $agreements->firstWhere('id', $r->agreement_id)?->reference, ['to' => '/stipend-runs/$id', 'params' => ['id' => $r->id]])
                    : self::item('warning', 'stipend', "Stipends for {$month} confirmed: pay and upload the proof", (string) $agreements->firstWhere('id', $r->agreement_id)?->reference, ['to' => '/stipend-runs/$id', 'params' => ['id' => $r->id]]);
            }
            $noBank = StipendLine::query()->whereIn('run_id', $runs->pluck('id'))->where('reason', 'No bank details.')->count();
            if ($noBank > 0) {
                $items[] = self::item('warning', 'bank', "{$noBank} learner stipend(s) held: no bank details", 'Ask the learners to add them in the app, or add them on the agreement page.',
                    ['to' => '/stipend-runs/$id', 'params' => ['id' => (string) $runs->first()?->id]]);
            }
            $year = Carbon::now()->startOfYear();
            $funding = [
                'claimable_cents' => $claimable,
                'claimed_open_cents' => (int) $claims->whereIn('status', ['draft', 'sent', 'queried'])->sum('amount_cents'),
                'paid_this_year_cents' => (int) $claims->where('status', 'paid')->filter(fn (GrantClaim $c) => $c->paid_on !== null && $c->paid_on->gte($year))->sum('paid_cents'),
                'stipends_held' => (int) StipendLine::query()->whereIn('run_id', $runs->pluck('id'))->where('status', 'hold')->count(),
            ];
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn (array $a, array $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return ['items' => $items, 'funding' => $funding];
    }

    /**
     * @param  array{to: string, params: array<string, string>}  $link
     * @return array<string, mixed>
     */
    private static function item(string $severity, string $kind, string $title, string $detail, array $link): array
    {
        return ['severity' => $severity, 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'link' => $link];
    }
}
