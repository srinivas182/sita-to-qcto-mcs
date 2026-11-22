<?php

declare(strict_types=1);

namespace Modules\Messaging\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Messaging\Drivers\MetaWhatsApp;
use Modules\Messaging\Services\Drivers;
use Modules\Messaging\Services\MessageService;
use Modules\Messaging\Support\CallbackKey;

/** Delivery receipts and WhatsApp status webhooks, verified per provider. */
final class WebhookController
{
    /** Meta's webhook set-up handshake. */
    public function verify(Request $request, string $driver): Response
    {
        $integration = app(Drivers::class)->configured($driver) ?? abort(404);
        abort_unless($driver === 'whatsapp.meta', 404);

        $token = (string) ($integration->value('verify_token') ?: CallbackKey::for($integration));
        $challenge = (new MetaWhatsApp($integration))->verifyChallenge($request, $token);

        return $challenge === null ? response('forbidden', 403) : response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $driver): Response
    {
        $drivers = app(Drivers::class);
        $integration = $drivers->configured($driver) ?? abort(404);
        $receipts = $drivers->make($integration)->receipts($request);

        if ($receipts === null) {
            return response('invalid signature', 401);
        }

        $updated = app(MessageService::class)->applyReceipts($driver, $receipts);

        return response((string) $updated, 200);
    }
}
