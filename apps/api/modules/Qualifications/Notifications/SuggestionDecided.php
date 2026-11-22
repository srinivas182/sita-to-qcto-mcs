<?php

declare(strict_types=1);

namespace Modules\Qualifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Carries plain values only: queued mail must not re-load RLS-protected rows. */
final class SuggestionDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly bool $accepted, public readonly string $response) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->accepted ? 'Added to the catalogue: ' : 'Catalogue suggestion answered: ').$this->title)
            ->line($this->accepted
                ? "Thank you. Your suggestion \"{$this->title}\" has been taken into the qualification catalogue."
                : "Your suggestion \"{$this->title}\" was not taken into the catalogue.")
            ->line($this->response);
    }
}
