<?php

declare(strict_types=1);

namespace Modules\Employers\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Plain values only: queued mail must not re-load RLS-protected rows. */
final class WorkplaceApprovalExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $employer,
        public readonly string $site,
        public readonly string $approval,
        public readonly string $date,
        public readonly int $days,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->days < 0 ? "Workplace approval expired: {$this->employer}, {$this->site}" : "Workplace approval ends in {$this->days} days: {$this->employer}, {$this->site}")
            ->greeting("Hello {$this->provider},")
            ->line("The SETA workplace approval {$this->approval} for {$this->employer} ({$this->site}) ".($this->days < 0 ? "ended on {$this->date}." : "ends on {$this->date}."))
            ->line($this->days < 0
                ? 'No new learners can be placed there until it is renewed. Learners already placed should be discussed with the SETA.'
                : 'Ask the employer to renew it with the SETA, then update the date and letter here.')
            ->action('Open employers', 'https://'.$this->code.'.'.config('platform.base_domain').'/employers');
    }
}
