<?php

declare(strict_types=1);

namespace Modules\Identity\Otp;

/** One delivery channel for sign-in PINs (WhatsApp, SMS, email). */
interface OtpChannel
{
    public function name(): string;

    /** @return bool true when the provider accepted the message */
    public function send(string $destination, string $message): bool;
}
