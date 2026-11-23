<?php

declare(strict_types=1);

namespace Modules\Delivery\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Plain values only: queued mail must not re-load RLS-protected rows. */
final class AttendanceAtRisk extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<array{name: string, pct: int}>  $learners */
    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $cohortId,
        public readonly string $cohort,
        public readonly int $threshold,
        public readonly array $learners,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)
            ->subject(count($this->learners)." learners below {$this->threshold}% attendance: {$this->cohort}")
            ->greeting("Hello {$this->provider},")
            ->line("These learners in {$this->cohort} have attended less than {$this->threshold}% of their classes (excused absences left out):");
        foreach ($this->learners as $l) {
            $m->line("• {$l['name']}: {$l['pct']}%");
        }

        return $m->line('Each of them has been sent a message. A SETA may stop a learner\'s stipend for poor attendance, so a quick call often helps.')
            ->action('Open attendance', 'https://'.$this->code.'.'.config('platform.base_domain').'/cohorts/'.$this->cohortId.'/attendance');
    }
}
