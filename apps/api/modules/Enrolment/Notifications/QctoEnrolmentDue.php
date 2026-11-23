<?php

declare(strict_types=1);

namespace Modules\Enrolment\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Plain values only: queued mail must not re-load RLS-protected rows. */
final class QctoEnrolmentDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $cohortId,
        public readonly string $cohort,
        public readonly string $due,
        public readonly int $days,
        public readonly int $learners,
        public readonly int $notReady,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->days < 0 ? 'was due on '.$this->due : ($this->days === 0 ? 'is due today' : "is due on {$this->due}, in {$this->days} days");

        return (new MailMessage)
            ->subject($this->days < 0 ? "Overdue: QCTO learner enrolment for {$this->cohort}" : "QCTO learner enrolment due: {$this->cohort}")
            ->greeting("Hello {$this->provider},")
            ->line("The QCTO learner enrolment file for {$this->cohort} ({$this->learners} learners not yet sent) {$when}.")
            ->line($this->notReady > 0 ? "{$this->notReady} learners still have missing details. The list shows exactly what to fill in." : 'Every learner\'s details are complete: download the file and email it to learnerenrolments@qcto.org.za.')
            ->action('Open the QCTO list', 'https://'.$this->code.'.'.config('platform.base_domain').'/cohorts/'.$this->cohortId.'/qcto');
    }
}
