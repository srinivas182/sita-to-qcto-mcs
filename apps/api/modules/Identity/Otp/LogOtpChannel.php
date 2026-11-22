<?php

declare(strict_types=1);

namespace Modules\Identity\Otp;

use Illuminate\Support\Facades\Log;
use Modules\Identity\Support\PhoneNumber;

/**
 * Development and test channel. Writes the message to the log with the destination
 * masked. Real WhatsApp/SMS providers plug in behind OtpChannel (Sprint 2 gateways).
 */
final class LogOtpChannel implements OtpChannel
{
    /** @var list<array{destination: string, message: string, channel: string}> */
    public static array $sent = [];

    public function __construct(private readonly string $channel) {}

    public function name(): string
    {
        return $this->channel;
    }

    public function send(string $destination, string $message): bool
    {
        self::$sent[] = ['destination' => $destination, 'message' => $message, 'channel' => $this->channel];

        Log::channel(config('logging.default'))->info('otp.sent', [
            'channel' => $this->channel,
            'to' => str_starts_with($destination, '+') ? PhoneNumber::mask($destination) : '[email]',
        ]);

        return true;
    }
}
