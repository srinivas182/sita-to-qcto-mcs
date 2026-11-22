<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use Modules\Identity\Otp\LogOtpChannel;
use Modules\Identity\Otp\OtpChannel;

/**
 * Sign-in codes through the providers in Settings. With none switched on, local
 * development and tests fall back to the log channel; production reports "not sent"
 * so the next channel is tried.
 */
final class MessagingOtpChannel implements OtpChannel
{
    public function __construct(private readonly string $channel) {}

    public function name(): string
    {
        return $this->channel;
    }

    public function send(string $destination, string $message, ?string $code = null): bool
    {
        // Resolved per send: the dispatcher is a singleton, but the tenant context is
        // per request (and per job in long-lived workers).
        $messages = app(MessageService::class);

        if (! $messages->hasProviders($this->channel)) {
            return app()->environment(['local', 'testing'])
                ? (new LogOtpChannel($this->channel))->send($destination, $message, $code)
                : false;
        }

        return $messages->code($this->channel, $destination, $code ?? '', $message)->sent;
    }
}
