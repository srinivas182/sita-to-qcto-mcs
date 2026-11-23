<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Intake;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Support\FileStatus;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/** A PDF of the application as received, kept on the record. Never blocks applying. */
final class ApplicationPdf
{
    public function __construct(private readonly TenantContext $context, private readonly StoreFactory $stores) {}

    public function keep(Application $application): ?File
    {
        try {
            $bytes = $this->render($application);
            [$store, $integration] = $this->stores->current();
            $fileId = (string) Str::ulid();
            $path = sprintf('%s/learner_application/%s/%s.pdf', $application->tenant_id, now()->format('Y/m'), strtolower($fileId));
            $store->disk()->put($path, $bytes);
            $file = File::query()->create([
                'id' => $fileId, 'tenant_id' => $application->tenant_id, 'owner_id' => $application->user_id, 'purpose' => 'learner_application',
                'original_name' => $application->reference.'.pdf', 'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'store_driver' => $store->driver(), 'integration_id' => $integration?->id, 'path' => $path, 'status' => FileStatus::Ready,
                'uploaded_at' => now(), 'scanned_at' => now(),
            ]);
            $application->forceFill(['pdf_file_id' => $file->id])->saveQuietly();

            return $file;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function render(Application $application): string
    {
        $intake = Intake::query()->whereKey($application->intake_id)->firstOrFail();
        /** @var User $u */
        $u = $this->context->runAsPlatform(fn () => User::query()->whereKey($application->user_id)->firstOrFail());
        $tenantId = $application->tenant_id;
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $answers = [];
        foreach ($intake->questions ?? [] as $q) {
            $v = $application->answers[$q['key']] ?? null;
            $answers[] = ['label' => (string) $q['label'], 'value' => $v === null ? '—' : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v)];
        }
        $html = view('enrolment::application', [
            'provider' => $tenant->trading_name, 'reference' => $application->reference, 'intake' => $intake->title,
            'submitted' => $application->submitted_at->format('j F Y H:i'), 'source' => $application->source === 'app' ? 'the app' : 'the web',
            'name' => $u->fullName(), 'id_number' => (string) $u->maskedIdNumber(), 'dob' => $u->dob?->format('j F Y') ?? '—',
            'phone' => $u->phone_e164 ?? '—', 'email' => $u->email ?? '—', 'answers' => $answers,
        ])->render();
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
