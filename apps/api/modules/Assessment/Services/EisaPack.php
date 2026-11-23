<?php

declare(strict_types=1);

namespace Modules\Assessment\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Assessment\Http\Controllers\ModerationController;
use Modules\Assessment\Models\EisaRegistration;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\LearnerDocument;
use Modules\Enrolment\Models\QctoOutcome;
use Modules\Enrolment\Models\QctoSubmission;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use ZipArchive;

/**
 * The EISA registration pack the quality partner asks for, per learner: ID copy, Statement
 * of Results, the signed declaration and proof of the QCTO submission. Made on request and
 * not stored, because it holds ID copies.
 */
final class EisaPack
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit, private readonly StoreFactory $stores) {}

    /** @param  list<EisaRegistration>  $registrations */
    public function zip(array $registrations): string
    {
        if ($registrations === []) {
            throw ValidationException::withMessages(['pack' => 'No registered learners yet.']);
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'eisa');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($registrations as $r) {
            $this->add($zip, $r, count($registrations) > 1);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function add(ZipArchive $zip, EisaRegistration $r, bool $folders): void
    {
        $learner = $this->context->runAsPlatform(fn () => User::query()->whereKey($r->user_id)->firstOrFail());
        $c = Cohort::query()->whereKey($r->cohort_id)->firstOrFail();
        $q = $this->context->runAsPlatform(fn () => Qualification::query()->whereKey($c->qualification_id)->firstOrFail());
        $tenantId = (string) $r->getAttribute('tenant_id');
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $provider = $tenant->legal_name ?: $tenant->trading_name;
        $dir = $folders ? Str::slug($learner->last_name.' '.$learner->first_name, ' ').'/' : '';
        $readme = ["EISA registration pack: {$learner->fullName()}", "EISA {$r->eisa_on->format('j F Y')} at {$r->assessment_centre_name} ({$r->assessment_centre})", ''];

        $doc = LearnerDocument::query()->where('user_id', $r->user_id)->where('type', 'id_document')->latest()->first();
        $file = $doc ? File::query()->whereKey($doc->file_id)->where('status', 'ready')->first() : null;
        if ($file !== null) {
            $ext = pathinfo($file->original_name, PATHINFO_EXTENSION) ?: 'pdf';
            $zip->addFromString($dir."1-id-copy.{$ext}", (string) $this->stores->forFile($file)->disk()->get($file->path));
            $readme[] = '1. ID copy: included';
        } else {
            $readme[] = '1. ID copy: MISSING (no ID document uploaded)';
        }
        $sor = StatementOfResults::query()->where('enrolment_id', $r->enrolment_id)->where('status', 'issued')->first();
        if ($sor !== null) {
            $zip->addFromString($dir.'2-statement-of-results.pdf', app(StatementService::class)->pdf($sor));
            $readme[] = "2. Statement of Results {$sor->number}: included";
        } else {
            $readme[] = '2. Statement of Results: MISSING';
        }
        $zip->addFromString($dir.'3-eisa-declaration.pdf', ModerationController::pdfFrom(view('assessment::eisa-declaration', [
            'r' => $r, 'provider' => $provider, 'learner' => $learner->fullName(), 'idNumber' => $learner->maskedIdNumber(), 'qualification' => $q->title, 'saqa' => $q->saqa_id,
            'sor' => $sor->number ?? '—', 'flc' => EisaService::FLC[$r->flc_code ?? ''] ?? '—', 'declaration' => EisaService::DECLARATION,
        ])->render()));
        $readme[] = '3. Registration and learner declaration: included'.($r->declared_at === null ? ' (NOT SIGNED)' : '');
        $subs = QctoSubmission::query()->where('cohort_id', $c->id)->orderBy('sent_on')->get()->filter(fn (QctoSubmission $s) => in_array($r->user_id, $s->learner_ids, true));
        $outcomes = QctoOutcome::query()->whereIn('submission_id', $subs->pluck('id'))->where('user_id', $r->user_id)->get()->keyBy('submission_id');
        $zip->addFromString($dir.'4-qcto-submission.pdf', ModerationController::pdfFrom(view('assessment::qcto-proof', [
            'provider' => $provider, 'cohort' => $c->name, 'learner' => $learner->fullName(), 'idNumber' => $learner->maskedIdNumber(),
            'submissions' => $subs->map(fn (QctoSubmission $s) => ['sent_on' => $s->sent_on->format('j M Y'), 'rows' => $s->rows, 'note' => $s->note,
                'reply' => match ($outcomes->get($s->id)?->status) {
                    'accepted' => 'Accepted', 'rejected' => 'Rejected: '.(string) $outcomes->get($s->id)->reason, default => 'No reply loaded'
                }])->values()->all(),
        ])->render()));
        $readme[] = '4. Proof of submission to the QCTO: included'.($subs->isEmpty() ? ' (NOT SENT YET)' : '');
        $zip->addFromString($dir.'README.txt', implode("\n", $readme)."\n");
        $this->audit->record('eisa.pack_downloaded', $r);
    }
}
