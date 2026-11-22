<?php

declare(strict_types=1);

namespace Modules\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $role,
        public readonly string $url,
        public readonly string $invitedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = (string) (config("access.roles.{$this->role}.label") ?? $this->role);

        return (new MailMessage)
            ->subject("You're invited to {$this->tenantName}")
            ->greeting('Hello,')
            ->line("{$this->invitedBy} invited you to join {$this->tenantName} as {$label}.")
            ->action('Accept invitation', $this->url)
            ->line('This invitation expires in '.config('identity.invitations.ttl_days', 7).' days.')
            ->line('If you were not expecting it, you can ignore this email.');
    }
}
