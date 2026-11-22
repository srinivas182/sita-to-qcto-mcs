<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Package;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingRules;
use Modules\Billing\Services\InvoiceService;
use Modules\Billing\Services\PaymentService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/** Super Admin billing: packages, subscriptions, invoices, EFTs and credit notes. Platform context. */
final class BillingAdminController
{
    public function overview(): JsonResponse
    {
        $byStatus = Subscription::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $overdue = Invoice::query()->toBase()->where('status', 'open')->where('due_at', '<', now())
            ->selectRaw('count(*) as n, coalesce(sum(total_cents - amount_paid_cents - amount_credited_cents), 0) as cents')->first();
        $month = Payment::query()->toBase()->where('status', 'succeeded')->where('paid_at', '>=', now()->startOfMonth())
            ->selectRaw('driver, count(*) as n, sum(amount_cents) as cents')->groupBy('driver')->get();

        // Monthly recurring revenue from base fees (excl. VAT and learner usage).
        $mrr = Subscription::query()->whereIn('status', ['active', 'past_due', 'read_only'])->with('package')->get()
            ->sum(fn (Subscription $s) => $s->interval === 'year' ? intdiv($s->package->baseFeeFor('year'), 12) : $s->package->base_fee_cents);

        return response()->json([
            'subscriptions' => $byStatus,
            'mrr_cents' => $mrr,
            'overdue' => ['count' => (int) ($overdue->n ?? 0), 'amount_cents' => (int) ($overdue->cents ?? 0)],
            'collected_this_month' => $month->map(fn ($r) => ['driver' => $r->driver, 'count' => (int) $r->n, 'amount_cents' => (int) $r->cents])->all(),
        ]);
    }

    public function packages(): JsonResponse
    {
        return response()->json(['data' => Package::query()->orderBy('sort')->get()->map(fn (Package $p) => $p->present())->all()]);
    }

    public function storePackage(Request $request): JsonResponse
    {
        $package = Package::query()->create($this->validatePackage($request, null));

        return response()->json(['data' => $package->present()], 201);
    }

    public function updatePackage(Request $request, string $package): JsonResponse
    {
        $model = Package::query()->findOrFail($package);
        $model->fill($this->validatePackage($request, $model))->save();

        return response()->json(['data' => $model->present()]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $query = Subscription::query()->with('package')->orderByDesc('created_at');
        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }
        $page = $query->paginate(25);
        $tenants = Tenant::query()->whereIn('id', collect($page->items())->pluck('tenant_id'))->get()->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Subscription $s) => [
                'id' => $s->id,
                'tenant' => ['id' => $s->tenant_id, 'name' => $tenants->get($s->tenant_id)?->trading_name, 'code' => $tenants->get($s->tenant_id)?->code],
                'package' => $s->package->name,
                'status' => $s->status->value,
                'interval' => $s->interval,
                'collection' => $s->collection,
                'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
                'current_period_end' => $s->current_period_end?->toIso8601String(),
                'past_due_since' => $s->past_due_since?->toIso8601String(),
            ])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $query = Invoice::query()->orderByDesc('issued_at');
        $status = (string) $request->query('status', '');
        if ($status === 'overdue') {
            $query->where('status', 'open')->where('due_at', '<', now());
        } elseif ($status !== '') {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('number', 'ilike', "%{$search}%")->orWhere('eft_reference', 'ilike', "%{$search}%"));
        }
        $page = $query->paginate(25);
        $tenants = Tenant::query()->whereIn('id', collect($page->items())->pluck('tenant_id'))->get()->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $i) => [
                ...$i->present(),
                'tenant' => ['id' => $i->tenant_id, 'name' => $tenants->get($i->tenant_id)?->trading_name],
            ])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Record an EFT that arrived in the JV's bank account. */
    public function recordEft(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'bank_reference' => ['required', 'string', 'max:120'],
        ]);
        [$model, $tenant] = $this->invoiceWithTenant($invoice);
        /** @var User $actor */
        $actor = $request->user();

        $payment = $this->context()->runAs($tenant, fn () => app(PaymentService::class)->recordEft(
            Invoice::query()->findOrFail($model->id), (int) $data['amount_cents'], $data['bank_reference'], $actor->id,
        ));

        return response()->json(['data' => $payment->present()], 201);
    }

    public function credit(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        [$model, $tenant] = $this->invoiceWithTenant($invoice);
        /** @var User $actor */
        $actor = $request->user();

        $note = $this->context()->runAs($tenant, fn () => app(InvoiceService::class)->credit(
            Invoice::query()->findOrFail($model->id), (int) $data['amount_cents'], $data['reason'], $actor->id,
        ));

        return response()->json(['data' => ['id' => $note->id, 'number' => $note->number, 'total_cents' => $note->total_cents]], 201);
    }

    public function pdf(string $invoice): Response
    {
        [$model, $tenant] = $this->invoiceWithTenant($invoice);

        return $this->context()->runAs($tenant, fn () => InvoiceController::streamPdf('invoice', $model->id));
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => app(BillingRules::class)->get()]);
    }

    public function updateRules(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => app(BillingRules::class)->update($request->all(), $actor)]);
    }

    /** @return array{0: Invoice, 1: Tenant} */
    private function invoiceWithTenant(string $id): array
    {
        $invoice = Invoice::query()->findOrFail($id);

        return [$invoice, Tenant::query()->findOrFail($invoice->tenant_id)];
    }

    /** @return array<string, mixed> */
    private function validatePackage(Request $request, ?Package $existing): array
    {
        $required = $existing === null ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$required, 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', 'unique:packages,code'.($existing ? ','.$existing->id : '')],
            'name' => [$required, 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'features' => ['nullable', 'array', 'max:20'],
            'features.*' => ['string', 'max:120'],
            'base_fee_cents' => [$required, 'integer', 'min:0', 'max:100000000'],
            'per_learner_cents' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'included_learners' => ['sometimes', 'integer', 'min:0'],
            'max_learners' => ['nullable', 'integer', 'min:1'],
            'max_centres' => ['nullable', 'integer', 'min:1'],
            'annual_discount_percent' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'is_example' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'public' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
