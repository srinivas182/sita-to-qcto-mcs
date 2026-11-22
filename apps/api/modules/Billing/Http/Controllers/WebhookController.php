<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Billing\Gateways\Gateways;
use Modules\Billing\Services\PaymentService;

/**
 * Payment webhooks. Verified by the gateway's own signature, stored once, then
 * applied. Always answers quickly; unknown or invalid calls get 400/401.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $driver): Response
    {
        $gateway = app(Gateways::class)->configured($driver) ?? abort(404);
        $event = $gateway->parseWebhook($request);

        if ($event === null) {
            return response('invalid signature', 401);
        }

        $result = app(PaymentService::class)->handleEvent($driver, $event);

        return response($result, 200);
    }
}
