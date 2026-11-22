<?php

declare(strict_types=1);

namespace Modules\Messaging\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Context\TenantContext;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;

/**
 * The "platform" mailer sends through the email service switched on in Settings
 * (SMTP or Amazon SES), with its sender address. With none switched on it falls back
 * to the mailer in MAIL_FALLBACK (log by default).
 */
final class PlatformMailer
{
    public function __construct(private readonly MailManager $mail, private readonly TenantContext $context) {}

    public function transport(): TransportInterface
    {
        $integration = $this->integration();

        if ($integration === null) {
            return $this->mail->mailer((string) config('mail.platform_fallback', 'log'))->getSymfonyTransport();
        }

        return match ($integration->driver) {
            'email.ses' => $this->mail->createSymfonyTransport([
                'transport' => 'ses',
                'key' => $integration->value('key'),
                'secret' => $integration->value('secret'),
                'region' => $integration->value('region', 'af-south-1'),
            ]),
            default => $this->mail->createSymfonyTransport([
                'transport' => 'smtp',
                'host' => $integration->value('host'),
                'port' => (int) $integration->value('port', 587),
                'encryption' => $integration->value('encryption') === 'none' ? null : $integration->value('encryption', 'tls'),
                'username' => $integration->value('username'),
                'password' => $integration->value('password'),
                'timeout' => 15,
            ]),
        };
    }

    /** Use the configured sender on every message sent through the platform mailer. */
    public function applySender(MessageSending $event): void
    {
        if (($event->data['mailer'] ?? null) !== 'platform' && config('mail.default') !== 'platform') {
            return;
        }

        $integration = $this->integration();
        $address = $integration?->value('from_address');

        if (is_string($address) && $address !== '') {
            $event->message->from(new Address($address, (string) $integration->value('from_name', config('app.name'))));
        }
    }

    private function integration(): ?Integration
    {
        return $this->context->runAsPlatform(fn () => Integration::query()->active('email')->first());
    }
}
