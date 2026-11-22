<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Audit\AuditLogger;
use Modules\Billing\Exceptions\BillingError;
use Modules\Billing\Gateways\ChargeResult;
use Modules\Billing\Gateways\CheckoutRequest;
use Modules\Billing\Gateways\CheckoutSession;
use Modules\Billing\Gateways\GatewayError;
use Modules\Billing\Gateways\GatewayEventData;
use Modules\Billing\Gateways\Gateways;
use Modules\Billing\Gateways\SavedCard;
use Modules\Billing\Models\BillingProfile;
use Modules\Billing\Models\GatewayEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\PaymentFailed;
use Modules\Billing\Notifications\PaymentReceived;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Taking payments: hosted checkout, saved-card charges, webhooks and manual EFT.
 * A payment is applied to its invoice once, whichever route reports it first.
 */
final class PaymentService
{
    public function __construct(
        private readonly Gateways $gateways,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start paying an open invoice on a gateway's hosted page. Runs in the provider's context.
     *
     * @return array{payment: Payment, session: CheckoutSession}
     */
    public function checkout(Tenant $tenant, Invoice $invoice, string $driver, string $method, bool $saveCard, string $returnUrl): array
    {
        if ($invoice->status !== 'open' || $invoice->amountDue() === 0) {
            throw new BillingError('This invoice has nothing to pay.', 'nothing_due', 409);
        }

        try {
            $gateway = $this->gateways->get($driver);
        } catch (GatewayError $e) {
            throw new BillingError($e->getMessage(), 'gateway_unavailable');
        }

        $profile = BillingProfile::query()->find($tenant->id);
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'reference' => $this->reference(),
            'driver' => $driver,
            'method' => $method,
            'amount_cents' => $invoice->amountDue(),
            'status' => 'pending',
            'save_method' => $saveCard,
        ]);

        try {
            $session = $gateway->checkout(new CheckoutRequest(
                reference: $payment->reference,
                amountCents: $payment->amount_cents,
                email: $profile !== null ? $profile->email : 'billing@'.$tenant->code.'.invalid',
                description: 'Invoice '.$invoice->number,
                returnUrl: $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'payment='.$payment->reference,
                cancelUrl: $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'payment='.$payment->reference.'&cancelled=1',
                notifyUrl: Gateways::webhookUrl($driver),
                saveMethod: $saveCard,
                method: $method,
                customerName: $profile?->legal_name,
            ));
        } catch (GatewayError $e) {
            $payment->fill(['status' => 'failed', 'failure_message' => mb_substr($e->getMessage(), 0, 300)])->save();

            throw new BillingError($e->getMessage(), 'gateway_error', 502);
        }

        if ($session->gatewayReference) {
            $payment->forceFill(['gateway_reference' => $session->gatewayReference])->save();
        }

        return ['payment' => $payment, 'session' => $session];
    }

    /** Charge the saved card for an invoice (renewals and retries). */
    public function chargeSaved(Tenant $tenant, Invoice $invoice, PaymentMethod $method): Payment
    {
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'reference' => $this->reference(),
            'driver' => $method->driver,
            'method' => 'saved_card',
            'amount_cents' => $invoice->amountDue(),
            'status' => 'pending',
        ]);

        try {
            $result = $this->gateways->get($method->driver)->chargeSaved($method, $payment, 'Invoice '.$invoice->number);
        } catch (GatewayError $e) {
            $result = new ChargeResult('failed', message: $e->getMessage());
        }

        $this->applyResult($payment, $result);

        return $payment->refresh();
    }

    /** The customer is back from the gateway: ask it how the payment ended. */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->status !== 'pending') {
            return $payment;
        }

        $gateway = $this->gateways->configured($payment->driver);

        if ($gateway !== null) {
            try {
                $this->applyResult($payment, $gateway->lookup($payment));
            } catch (GatewayError) {
                // The webhook will settle it.
            }
        }

        return $payment->refresh();
    }

    /**
     * A verified webhook. Stored once per gateway event; applied in the provider's
     * context found from our payment reference.
     */
    public function handleEvent(string $driver, GatewayEventData $event): string
    {
        return $this->context->runAsPlatform(function () use ($driver, $event): string {
            $stored = GatewayEvent::query()->firstOrCreate(
                ['driver' => $driver, 'event_id' => $event->eventId],
                ['type' => $event->type, 'reference' => $event->reference, 'payload' => (string) json_encode($event->payload)],
            );

            if (! $stored->wasRecentlyCreated && $stored->processed_at !== null) {
                return 'duplicate';
            }

            $payment = $event->reference !== null ? Payment::query()->where('reference', $event->reference)->first() : null;
            $result = 'ignored';

            if ($payment !== null && $event->type !== 'ignored') {
                $tenant = Tenant::query()->findOrFail($payment->tenant_id);

                if ($event->amountCents !== null && $event->type === 'payment.succeeded' && $event->amountCents !== $payment->amount_cents) {
                    $result = 'amount_mismatch';
                    $this->audit->record('payment.amount_mismatch', $payment, meta: ['expected' => $payment->amount_cents, 'received' => $event->amountCents], tenantId: $payment->tenant_id);
                } else {
                    $status = match ($event->type) {
                        'payment.succeeded' => 'succeeded',
                        'payment.failed' => 'failed',
                        default => 'cancelled',
                    };
                    $this->context->runAs($tenant, fn () => $this->applyResult(
                        Payment::query()->findOrFail($payment->id),
                        new ChargeResult($status, $event->gatewayReference, $event->message, $event->card, $event->amountCents),
                    ));
                    $result = $status;
                }
            }

            $stored->fill(['result' => $result, 'processed_at' => now()])->save();

            return $result;
        });
    }

    /** Record an EFT that arrived in the bank account (Super Admin). Runs in the provider's context. */
    public function recordEft(Invoice $invoice, int $amountCents, string $bankReference, string $actorId): Payment
    {
        if ($invoice->status !== 'open') {
            throw new BillingError('This invoice is not open.', 'not_open', 409);
        }

        if ($amountCents <= 0 || $amountCents > $invoice->amountDue()) {
            throw new BillingError('The amount must be more than zero and no more than what is due.', 'invalid_amount');
        }

        $payment = Payment::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'reference' => $this->reference(),
            'driver' => 'manual.eft',
            'gateway_reference' => $bankReference,
            'method' => 'eft',
            'amount_cents' => $amountCents,
            'status' => 'pending',
            'recorded_by' => $actorId,
        ]);

        $this->applyResult($payment, new ChargeResult('succeeded', $bankReference, 'EFT received'));

        return $payment->refresh();
    }

    /** Apply an outcome to a payment and its invoice, once. */
    private function applyResult(Payment $payment, ChargeResult $result): void
    {
        if ($result->status === 'pending' || $payment->status !== 'pending') {
            return;
        }

        $tenant = Tenant::query()->find($payment->tenant_id) ?? $this->context->runAsPlatform(fn () => Tenant::query()->findOrFail($payment->tenant_id));

        DB::transaction(function () use ($payment, $result, $tenant): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);

            $payment->fill([
                'status' => $result->status === 'succeeded' ? 'succeeded' : ($result->status === 'cancelled' ? 'cancelled' : 'failed'),
                'gateway_reference' => $result->gatewayReference ?? $payment->gateway_reference,
                'failure_message' => $result->status === 'succeeded' ? null : mb_substr((string) $result->message, 0, 300),
                'paid_at' => $result->status === 'succeeded' ? now() : null,
            ])->save();

            if ($result->status !== 'succeeded') {
                return;
            }

            $invoice->amount_paid_cents += $payment->amount_cents;
            if ($invoice->amountDue() === 0) {
                $invoice->fill(['status' => 'paid', 'paid_at' => now(), 'next_attempt_at' => null]);
            }
            $invoice->save();

            if ($result->card !== null && ($payment->save_method || $payment->method === 'saved_card')) {
                $this->keepCard($tenant, $payment->driver, $result->card);
            }
        });

        $this->audit->record('payment.'.$payment->status, $payment, meta: ['amount_cents' => $payment->amount_cents, 'driver' => $payment->driver]);
        $this->notify($tenant, $payment);

        $subscription = Subscription::query()->first();
        if ($subscription !== null) {
            app(SubscriptionService::class)->refreshStatus($subscription);
        }
    }

    /** Keep a card for automatic renewals and make it the plan's card. */
    private function keepCard(Tenant $tenant, string $driver, SavedCard $card): void
    {
        $method = PaymentMethod::query()->where('driver', $driver)
            ->when($card->fingerprint, fn ($q) => $q->where('fingerprint', $card->fingerprint))
            ->first() ?? new PaymentMethod(['tenant_id' => $tenant->id, 'driver' => $driver]);

        $method->fill([
            'token' => $card->token,
            'customer_ref' => $card->customerRef,
            'brand' => $card->brand,
            'last4' => $card->last4,
            'exp_month' => $card->expMonth,
            'exp_year' => $card->expYear,
            'fingerprint' => $card->fingerprint,
        ])->save();

        Subscription::query()->update(['payment_method_id' => $method->id, 'collection' => 'auto']);
    }

    private function notify(Tenant $tenant, Payment $payment): void
    {
        $profile = BillingProfile::query()->find($tenant->id);
        if ($profile === null) {
            return;
        }

        $invoice = Invoice::query()->find($payment->invoice_id);
        if ($invoice === null) {
            return;
        }
        $notification = match ($payment->status) {
            'succeeded' => new PaymentReceived($tenant, $payment, $invoice),
            'failed' => new PaymentFailed($tenant, $payment, $invoice),
            default => null,
        };

        if ($notification !== null) {
            Notification::route('mail', $profile->email)->notify($notification);
        }
    }

    private function reference(): string
    {
        // 16 characters: within every gateway's limit (Peach allows 8–16).
        return 'P'.strtoupper(Str::random(15));
    }
}
