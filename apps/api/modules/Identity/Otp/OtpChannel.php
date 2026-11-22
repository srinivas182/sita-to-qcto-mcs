<?php

declare(strict_types=1);

namespace Modules\Identity\Otp;

/** One delivery channel for sign-in PINs (WhatsApp, SMS, email). */
interface OtpChannel
{
    public function name(): string;

    /**
     * @param  string|null  $code  the PIN on its own, for channels that send it in a
     *                             template (WhatsApp authentication templates)
     * @return bool true when the provider accepted the message
     */
    public function send(string $destination, string $message, ?string $code = null): bool;
}
