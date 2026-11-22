<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\CreditNote;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingDocuments;
use Modules\Billing\Services\PaymentService;
use Modules\Files\Storage\ContentDisposition;
use Modules\Files\Storage\StoreFactory;
use Modules\Tenancy\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/** The provider's invoices: list, view, PDF and pay. */
final class InvoiceController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $page = Invoice::query()->orderByDesc('issued_at')->paginate(min(50, max(5, (int) $request->query('per_page', '20'))));

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $i) => $i->present())->all(),
            'credit_notes' => CreditNote::query()->orderByDesc('issued_at')->limit(50)->get()->map(fn (CreditNote $c) => [
                'id' => $c->id, 'number' => $c->number, 'invoice_id' => $c->invoice_id, 'reason' => $c->reason,
                'total_cents' => $c->total_cents, 'issued_at' => $c->issued_at->toIso8601String(),
            ])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $invoice): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $model = Invoice::query()->with(['lines', 'payments'])->findOrFail($invoice);

        return response()->json(['data' => [
            ...$model->present(true),
            'payments' => $model->payments->sortByDesc('created_at')->values()->map(fn (Payment $p) => $p->present())->all(),
        ]]);
    }

    public function pdf(string $invoice): Response
    {
        Gate::authorize('tenant.billing.manage');

        return self::streamPdf('invoice', Invoice::query()->findOrFail($invoice)->id);
    }

    public function creditNotePdf(string $note): Response
    {
        Gate::authorize('tenant.billing.manage');

        return self::streamPdf('credit_note', CreditNote::query()->findOrFail($note)->id);
    }

    public function pay(Request $request, string $invoice): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $data = $request->validate([
            'driver' => ['required', 'string', 'max:40'],
            'method' => ['required', 'in:card,instant_eft'],
            'save_card' => ['sometimes', 'boolean'],
            'return_url' => ['required', 'url:https,http', 'max:500'],
        ]);

        // Only back to this provider's own host: never an open redirect.
        $returnHost = parse_url($data['return_url'], PHP_URL_HOST);
        abort_if($returnHost !== $request->getHost(), 422, 'The return address must be this site.');

        $tenant = app(TenantContext::class)->tenant() ?? abort(404);
        $result = app(PaymentService::class)->checkout(
            $tenant,
            Invoice::query()->findOrFail($invoice),
            $data['driver'],
            $data['method'],
            (bool) ($data['save_card'] ?? false) && $data['method'] === 'card',
            $data['return_url'],
        );

        return response()->json(['payment' => $result['payment']->present(), 'checkout' => $result['session']->toArray()], 201);
    }

    /** After returning from the gateway: the payment's outcome. */
    public function payment(string $reference): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();

        return response()->json(['data' => app(PaymentService::class)->refresh($payment)->present()]);
    }

    /** Stream a stored invoice or credit note PDF, rendering it first if needed. Context: the document's provider. */
    public static function streamPdf(string $type, string $id): Response
    {
        $file = app(BillingDocuments::class)->store($type, $id) ?? abort(404);
        $bytes = (string) app(StoreFactory::class)->forFile($file)->disk()->get($file->path);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($file->original_name),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
