<?php

declare(strict_types=1);

namespace Modules\People\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Plain values only: queued mail must not re-load RLS-protected rows. */
final class CredentialExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $person,
        public readonly string $role,
        public readonly string $registration,
        public readonly string $date,
        public readonly int $days,
        public readonly bool $toHolder,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->toHolder ? 'Your' : "{$this->person}'s";
        $subject = $this->days < 0 ? "{$who} {$this->role} registration has expired" : "{$who} {$this->role} registration ends in {$this->days} days";

        return (new MailMessage)
            ->subject($subject)
            ->greeting($this->toHolder ? "Hello {$this->person}," : "Hello {$this->provider},")
            ->line("{$who} {$this->role} registration {$this->registration} ".($this->days < 0 ? "ended on {$this->date}." : "ends on {$this->date}."))
            ->line($this->days < 0
                ? 'Until it is renewed, they cannot be assigned to facilitate, assess or moderate. Upload the renewed letter to restore it.'
                : 'Renew it in good time and upload the new letter, so classes and assessments are not held up.')
            ->action('Open registrations', 'https://'.$this->code.'.'.config('platform.base_domain').($this->toHolder ? '/profile' : '/people/credentials'));
    }
}
