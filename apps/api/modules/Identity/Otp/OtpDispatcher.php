<?php

declare(strict_types=1);

namespace Modules\Identity\Otp;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a PIN through the configured channels in order (WhatsApp first, SMS as
 * fallback), stopping at the first that accepts it.
 */
final class OtpDispatcher
{
    /** @param  list<OtpChannel>  $channels */
    public function __construct(private readonly array $channels) {}

    /** @return string|null name of the channel that delivered, or null if none did */
    public function deliver(string $destination, string $message, ?string $preferred = null, ?string $code = null): ?string
    {
        $channels = $this->channels;

        if ($preferred !== null) {
            usort($channels, fn (OtpChannel $a, OtpChannel $b): int => ($b->name() === $preferred) <=> ($a->name() === $preferred));
        }

        foreach ($channels as $channel) {
            try {
                if ($channel->send($destination, $message, $code)) {
                    return $channel->name();
                }
            } catch (Throwable $e) {
                Log::warning('otp.channel_failed', ['channel' => $channel->name(), 'error' => $e->getMessage()]);
            }
        }

        return null;
    }
}
