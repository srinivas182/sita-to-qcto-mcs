<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Modules\Billing\Models\CreditNote;
use Modules\Billing\Models\Invoice;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Support\FileStatus;

/** PDF tax invoices and credit notes (Dompdf, no remote resources). */
final class BillingDocuments
{
    public function __construct(private readonly StoreFactory $stores) {}

    public function render(string $type, Invoice|CreditNote $document): string
    {
        $html = view($type === 'invoice' ? 'billing::invoice' : 'billing::credit-note', [
            'doc' => $document,
            'invoice' => $document instanceof CreditNote ? Invoice::query()->find($document->invoice_id) : $document,
        ])->render();

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

    /** Render once and keep it; returns the file. Runs in the provider's context. */
    public function store(string $type, string $id): ?File
    {
        $document = $type === 'invoice' ? Invoice::query()->find($id) : CreditNote::query()->find($id);

        if ($document === null) {
            return null;
        }

        if ($document->pdf_file_id !== null && ($existing = File::query()->find($document->pdf_file_id)) !== null) {
            return $existing;
        }

        $bytes = $this->render($type, $document);
        [$store, $integration] = $this->stores->current();
        $fileId = (string) Str::ulid();
        $path = sprintf('%s/billing_document/%s/%s.pdf', $document->tenant_id, now()->format('Y/m'), strtolower($fileId));
        $store->disk()->put($path, $bytes);

        $file = File::query()->create([
            'id' => $fileId,
            'tenant_id' => $document->tenant_id,
            'purpose' => 'billing_document',
            'original_name' => ($type === 'invoice' ? 'Tax invoice ' : 'Credit note ').$document->number.'.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'store_driver' => $store->driver(),
            'integration_id' => $integration?->id,
            'path' => $path,
            'status' => FileStatus::Ready,
            'uploaded_at' => now(),
            'scanned_at' => now(),
        ]);

        $document->forceFill(['pdf_file_id' => $file->id])->save();

        return $file;
    }
}
