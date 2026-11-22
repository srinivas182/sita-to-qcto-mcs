<?php

declare(strict_types=1);

namespace Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly string $brand) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reset your {$this->brand} password")
            ->line('We received a request to reset your password.')
            ->action('Choose a new password', $this->url)
            ->line('This link expires in 60 minutes. If you did not ask for it, you can ignore this email.');
    }
}
