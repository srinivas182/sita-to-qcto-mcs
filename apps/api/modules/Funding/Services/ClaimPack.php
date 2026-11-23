<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

use Modules\Assessment\Http\Controllers\ModerationController;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Assessment\Services\StatementService;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Services\RegisterService;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Funding\Models\Funder;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantClaim;
use Modules\Funding\Models\GrantTranche;
use Modules\Funding\Models\StipendLine;
use Modules\Funding\Models\StipendRun;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;
use ZipArchive;

/**
 * Everything a funder asks for with a tranche claim, in one ZIP: the claim and invoice, the
 * learner schedule, the MoA, the tranche's evidence, registers, Statements of Results and
 * stipend proof. Built on request, never stored, every download audited.
 */
final class ClaimPack
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit, private readonly StoreFactory $stores) {}

    public function zip(GrantClaim $claim): string
    {
        $a = GrantAgreement::query()->whereKey($claim->agreement_id)->firstOrFail();
        $t = GrantTranche::query()->whereKey($claim->tranche_id)->firstOrFail();
        $funder = Funder::query()->whereKey($a->funder_id)->firstOrFail();
        $cohort = Cohort::query()->whereKey($a->cohort_id)->firstOrFail();
        $tenantId = (string) $claim->tenant_id;
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $q = $this->context->runAsPlatform(fn () => Qualification::query()->whereKey($cohort->qualification_id)->first());
        $people = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', array_column($claim->learners, 'user_id'))->get()->keyBy('id'));
        $rows = array_map(fn (array $l) => $l + ['id_number' => $people->get($l['user_id'])->id_number ?? ''], $claim->learners);

        $path = (string) tempnam(sys_get_temp_dir(), 'claim');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $readme = ["Claim {$claim->number}: {$funder->name}, agreement {$a->reference}, tranche {$t->number}", ''];

        $html = view('funding::claim', ['claim' => $claim, 'agreement' => $a, 'tranche' => $t, 'funder' => $funder, 'tenant' => $tenant, 'cohort' => $cohort,
            'centre' => Centre::query()->whereKey($cohort->centre_id)->first(), 'qualification' => $q, 'rows' => $rows,
            'milestones' => array_map(fn (array $m) => Milestones::label($m), $t->milestones), 'title' => $funder->template === 'ceta' ? 'CETA discretionary grant: tranche claim' : 'Grant tranche claim'])->render();
        $zip->addFromString('1-claim-and-invoice.pdf', ModerationController::pdfFrom($html));
        $readme[] = '1. Claim and invoice: included';
        $zip->addFromString('2-learner-schedule.csv', self::csv([['Learner', 'ID number', 'Amount', 'Disability allowance'], ...array_map(fn (array $r) => [
            (string) $r['name'], (string) $r['id_number'], number_format($r['amount_cents'] / 100, 2, '.', ''), number_format($r['disability_cents'] / 100, 2, '.', '')], $rows)]));
        $readme[] = '2. Learner schedule: included ('.count($rows).(count($rows) === 1 ? ' learner)' : ' learners)');

        $readme[] = $this->addFile($zip, $a->moa_file_id, '3-signed-agreement') ? '3. Signed agreement (MoA): included' : '3. Signed agreement (MoA): MISSING (not uploaded)';
        foreach ($t->evidence as $i => $e) {
            $ok = $this->addFile($zip, $e['file_id'], 'evidence/'.($i + 1).'-'.str($e['title'])->slug());
            $readme[] = '4. Evidence: '.$e['title'].($ok ? ': included' : ': MISSING');
        }

        $registers = AttendanceRegister::query()->where('cohort_id', $cohort->id)->whereIn('status', ['complete', 'submitted'])
            ->whereBetween('month', [$a->starts_on->copy()->startOfMonth()->toDateString(), $a->ends_on->toDateString()])->orderBy('month')->get();
        foreach ($registers as $r) {
            $zip->addFromString('registers/register-'.$r->month->format('Y-m').'.pdf', app(RegisterService::class)->pdf($r));
        }
        $readme[] = '5. Signed monthly registers: '.$registers->count();

        if (in_array('sor', array_column($t->milestones, 'key'), true)) {
            $sors = StatementOfResults::query()->whereIn('enrolment_id', array_column($claim->learners, 'enrolment_id'))->where('status', 'issued')->get();
            foreach ($sors as $s) {
                $zip->addFromString('statements/'.$s->number.'.pdf', app(StatementService::class)->pdf($s));
            }
            $readme[] = '6. Statements of Results: '.$sors->count().' of '.count($claim->learners);
        }

        $runs = StipendRun::query()->where('agreement_id', $a->id)->whereIn('status', ['confirmed', 'paid'])->orderBy('month')->get();
        if ($runs->isNotEmpty()) {
            $names = $people->map(fn (User $u) => $u->fullName());
            $lines = StipendLine::query()->whereIn('run_id', $runs->pluck('id'))->get();
            $csv = [['Month', 'Learner', 'Attendance %', 'Amount', 'Status', 'Paid on']];
            foreach ($runs as $run) {
                foreach ($lines->where('run_id', $run->id) as $l) {
                    $csv[] = [$run->month->format('Y-m'), (string) ($names->get($l->user_id) ?? ''), (string) ($l->attendance_pct ?? ''),
                        number_format($l->amount_cents / 100, 2, '.', ''), $l->status === 'hold' ? 'held' : ($run->status === 'paid' ? 'paid' : 'not paid yet'),
                        $run->paid_on?->toDateString() ?? ''];
                }
                $ok = $this->addFile($zip, $run->proof_file_id, 'stipends/proof-of-payment-'.$run->month->format('Y-m'));
                $readme[] = '7. Stipend proof of payment '.$run->month->format('F Y').($ok ? ': included' : ': MISSING');
            }
            $zip->addFromString('stipends/stipend-schedule.csv', self::csv($csv));
        }

        $zip->addFromString('README.txt', implode("\n", $readme)."\n");
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        $this->audit->record('claim.pack_downloaded', $claim, [], ['number' => $claim->number]);

        return $bytes;
    }

    private function addFile(ZipArchive $zip, ?string $fileId, string $name): bool
    {
        $f = $fileId === null ? null : File::query()->whereKey($fileId)->where('status', 'ready')->first();
        if ($f === null) {
            return false;
        }
        $ext = pathinfo($f->original_name, PATHINFO_EXTENSION) ?: 'pdf';
        $zip->addFromString("{$name}.{$ext}", (string) $this->stores->forFile($f)->disk()->get($f->path));

        return true;
    }

    /** @param  list<list<string>>  $rows */
    private static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Could not build the CSV.');
        }
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v, $r), ',', '"', '');
        }
        rewind($out);
        $s = (string) stream_get_contents($out);
        fclose($out);

        return $s;
    }
}
