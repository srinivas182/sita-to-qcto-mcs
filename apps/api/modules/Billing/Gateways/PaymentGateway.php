<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Http\Request;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;

/**
 * One payment gateway. Every gateway takes a hosted checkout (card data never
 * touches our servers) and reports the result by webhook; gateways that can keep a
 * card also charge it again for renewals.
 */
interface PaymentGateway
{
    public function driver(): string;

    /** Start a payment on the gateway's hosted page. */
    public function checkout(CheckoutRequest $request): CheckoutSession;

    /**
     * Check a webhook really came from the gateway and turn it into an event.
     * Returns null when the signature or source does not check out.
     */
    public function parseWebhook(Request $request): ?GatewayEventData;

    /** Charge a saved card. The outcome may also arrive later by webhook. */
    public function chargeSaved(PaymentMethod $method, Payment $payment, string $description): ChargeResult;

    /** Ask the gateway how a payment ended (after the customer returns). */
    public function lookup(Payment $payment): ChargeResult;
}
