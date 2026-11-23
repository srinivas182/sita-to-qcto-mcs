<?php

declare(strict_types=1);

namespace Modules\Agreements\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Signing emails: an invitation, a PIN, a reminder or the signed copy. Plain values only,
 * so queued mail never re-loads RLS-protected rows.
 */
final class AgreementMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'invite'|'reminder'|'code'|'completed'  $kind */
    public function __construct(
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $title,
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $code = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Hello {$this->name},");

        return match ($this->kind) {
            'code' => $mail->subject("Your signing PIN: {$this->code}")
                ->line("Use this PIN to sign \"{$this->title}\" for {$this->provider}:")
                ->line("**{$this->code}**")
                ->line('It works for 10 minutes. If you did not ask for it, ignore this email.'),
            'completed' => $mail->subject("Signed: {$this->title}")
                ->line("Everyone has signed \"{$this->title}\". Download your copy, or check it at any time, from the link below.")
                ->action('Get the signed copy', $this->url),
            'reminder' => $mail->subject("Reminder: please sign {$this->title}")
                ->line("{$this->provider} is still waiting for your signature on \"{$this->title}\".")
                ->action('Read and sign', $this->url),
            default => $mail->subject("Please sign: {$this->title}")
                ->line("{$this->provider} has sent you \"{$this->title}\" to read and sign.")
                ->line('You can sign on your phone. We send you a one-time PIN to confirm it is you.')
                ->action('Read and sign', $this->url),
        };
    }
}
