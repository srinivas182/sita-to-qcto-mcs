<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Exceptions\BillingError;
use Modules\Billing\Gateways\Gateways;
use Modules\Billing\Models\BillingProfile;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Package;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\InvoiceService;
use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** The provider's billing page: plan, usage, next invoice, card, details. */
final class BillingController
{
    public function show(): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $tenant = $this->tenant();
        $subscription = $this->subscriptions()->current();

        return response()->json([
            'subscription' => $subscription === null ? null : $this->presentSubscription($subscription),
            'estimate' => $subscription === null || $subscription->status->value === 'cancelled' ? null : app(InvoiceService::class)->estimate($tenant, $subscription),
            'profile' => BillingProfile::query()->find($tenant->id)?->snapshot(),
            'payment_method' => $subscription?->paymentMethod?->present(),
            'open_invoices' => Invoice::query()->where('status', 'open')->orderBy('due_at')->get()->map(fn (Invoice $i) => $i->present())->all(),
            'packages' => Package::query()->where('active', true)->where('public', true)->orderBy('sort')->get()->map(fn (Package $p) => $p->present())->all(),
            'gateways' => app(Gateways::class)->available(),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $profile = $this->subscriptions()->saveProfile($this->tenant(), $this->validateProfile($request));

        return response()->json(['profile' => $profile->snapshot()]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $data = $request->validate([
            'package_id' => ['required', 'ulid'],
            'interval' => ['required', 'in:month,year'],
        ]);
        $profile = $this->validateProfile($request, 'profile.');
        $package = Package::query()->whereKey($data['package_id'])->firstOrFail();

        $subscription = $this->subscriptions()->start($this->tenant(), $package, $data['interval'], $profile);

        return response()->json(['subscription' => $this->presentSubscription($subscription->load(['package', 'pendingPackage', 'paymentMethod']))], 201);
    }

    public function change(Request $request): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $data = $request->validate(['package_id' => ['required', 'ulid'], 'interval' => ['required', 'in:month,year']]);
        $subscription = $this->subscription();
        $package = Package::query()->whereKey($data['package_id'])->firstOrFail();

        $subscription = $this->subscriptions()->change($this->tenant(), $subscription, $package, $data['interval']);

        return response()->json(['subscription' => $this->presentSubscription($subscription->load('paymentMethod'))]);
    }

    public function cancel(): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $subscription = $this->subscriptions()->cancelAtPeriodEnd($this->subscription());

        return response()->json(['subscription' => $this->presentSubscription($subscription->load(['package', 'pendingPackage', 'paymentMethod']))]);
    }

    public function resume(): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $subscription = $this->subscriptions()->resume($this->subscription());

        return response()->json(['subscription' => $this->presentSubscription($subscription->load(['package', 'pendingPackage', 'paymentMethod']))]);
    }

    /** Pay automatically with the saved card, or pay each invoice yourself. */
    public function collection(Request $request): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        $data = $request->validate(['collection' => ['required', 'in:auto,invoice']]);
        $subscription = $this->subscription()->load('paymentMethod');

        if ($data['collection'] === 'auto' && $subscription->paymentMethod === null) {
            throw new BillingError('Save a card first: pay an invoice by card and tick "Save this card".', 'no_card');
        }

        $subscription->fill(['collection' => $data['collection']])->save();

        return response()->json(['subscription' => $this->presentSubscription($subscription->load(['package', 'pendingPackage']))]);
    }

    public function removeCard(): JsonResponse
    {
        Gate::authorize('tenant.billing.manage');
        Subscription::query()->update(['payment_method_id' => null, 'collection' => 'invoice']);
        PaymentMethod::query()->delete();

        return response()->json(['removed' => true]);
    }

    /** @return array<string, mixed> */
    private function presentSubscription(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->status->value,
            'interval' => $s->interval,
            'collection' => $s->collection,
            'package' => $s->package->present(),
            'pending_package' => $s->pendingPackage?->present(),
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'current_period_start' => $s->current_period_start?->toIso8601String(),
            'current_period_end' => $s->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $s->cancel_at_period_end,
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
            'past_due_since' => $s->past_due_since?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function validateProfile(Request $request, string $prefix = ''): array
    {
        $data = $request->validate([
            $prefix.'legal_name' => ['required', 'string', 'max:200'],
            $prefix.'vat_number' => ['nullable', 'regex:/^4\d{9}$/'],
            $prefix.'registration_number' => ['nullable', 'string', 'max:40'],
            $prefix.'address_line1' => ['required', 'string', 'max:200'],
            $prefix.'address_line2' => ['nullable', 'string', 'max:200'],
            $prefix.'city' => ['required', 'string', 'max:100'],
            $prefix.'province' => ['nullable', 'string', 'max:100'],
            $prefix.'postal_code' => ['nullable', 'string', 'max:10'],
            $prefix.'email' => ['required', 'email:rfc', 'max:254'],
            $prefix.'purchase_order' => ['nullable', 'string', 'max:60'],
        ], [$prefix.'vat_number.regex' => 'A South African VAT number has 10 digits and starts with 4.']);

        /** @var array<string, mixed> */
        return $prefix === '' ? $data : (array) ($data[rtrim($prefix, '.')] ?? []);
    }

    private function subscription(): Subscription
    {
        return Subscription::query()->with(['package', 'pendingPackage'])->first()
            ?? throw new BillingError('Choose a package first.', 'no_subscription', 404);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->tenant() ?? abort(404);
    }

    private function subscriptions(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }
}
