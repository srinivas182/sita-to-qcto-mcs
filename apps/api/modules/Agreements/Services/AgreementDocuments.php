<?php

declare(strict_types=1);

namespace Modules\Agreements\Services;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Support\AgreementText;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Support\FileStatus;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** The agreement PDF: the frozen text, then a page of signatures and evidence with a QR code. */
final class AgreementDocuments
{
    public const ROLES = ['learner' => 'Learner', 'employer' => 'Host employer', 'provider' => 'Training provider'];

    public function __construct(private readonly StoreFactory $stores, private readonly TenantContext $context) {}

    public static function verifyUrl(Agreement $agreement, Tenant $tenant): string
    {
        return 'https://'.$tenant->code.'.'.config('platform.base_domain').'/verify/'.$agreement->verify_code;
    }

    public function renderSealed(Agreement $agreement): string
    {
        return $this->render($agreement, true);
    }

    /** The text alone, for an advanced provider that adds its own signatures. */
    public function renderUnsigned(Agreement $agreement): string
    {
        return $this->render($agreement, false);
    }

    /** Seal a completed agreement signed here: render, store and fingerprint the PDF. */
    public function seal(Agreement $agreement): File
    {
        return $this->keep($agreement, $this->renderSealed($agreement));
    }

    /** Keep the PDF an advanced provider returned (it carries their signatures). */
    public function storeSigned(Agreement $agreement, string $bytes): File
    {
        return $this->keep($agreement, $bytes);
    }

    public function bytes(File $file): string
    {
        return (string) $this->stores->forFile($file)->disk()->get($file->path);
    }

    private function render(Agreement $agreement, bool $evidence): string
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->findOrFail($agreement->tenant_id));
        $url = self::verifyUrl($agreement, $tenant);
        $qr = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'outputBase64' => true, 'scale' => 6, 'quietzoneSize' => 1])))->render($url);
        $html = view('agreements::agreement', [
            'agreement' => $agreement,
            'provider' => $tenant->trading_name,
            'bodyHtml' => AgreementText::html($agreement->body),
            'signers' => $evidence ? $agreement->signers()->get() : collect(),
            'roles' => self::ROLES,
            'verifyUrl' => $url,
            'qr' => $qr,
        ])->render();
        if (! $evidence) {
            $html = (string) preg_replace('#<div class="evidence">.*</div>\s*</body>#s', '</body>', $html);
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path());
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    private function keep(Agreement $agreement, string $bytes): File
    {
        [$store, $integration] = $this->stores->current();
        $fileId = (string) Str::ulid();
        $path = sprintf('%s/agreement_document/%s/%s.pdf', $agreement->tenant_id, now()->format('Y/m'), strtolower($fileId));
        $store->disk()->put($path, $bytes);
        $file = File::query()->create([
            'id' => $fileId, 'tenant_id' => $agreement->tenant_id, 'purpose' => 'agreement_document',
            'original_name' => Str::limit(Str::slug($agreement->title), 80, '').'-signed.pdf', 'mime' => 'application/pdf',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'store_driver' => $store->driver(),
            'integration_id' => $integration?->id, 'path' => $path, 'status' => FileStatus::Ready, 'uploaded_at' => now(), 'scanned_at' => now(),
        ]);
        $agreement->forceFill(['sealed_file_id' => $file->id, 'sealed_sha256' => $file->sha256])->save();

        return $file;
    }
}
