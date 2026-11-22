<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Request;

/** One SMS or WhatsApp provider. */
interface MessagingDriver
{
    /** Send a plain text message (SMS). */
    public function sendText(string $to, string $text, string $reference): SendResult;

    /** Send a sign-in code. SMS sends the text; WhatsApp sends the authentication template. */
    public function sendCode(string $to, string $code, string $text, string $reference): SendResult;

    /**
     * Delivery receipts from a callback. Null when the callback is not genuine.
     *
     * @return list<Receipt>|null
     */
    public function receipts(Request $request): ?array;
}
