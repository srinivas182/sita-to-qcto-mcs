<?php

declare(strict_types=1);

namespace Modules\Cohorts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Plain values only: queued mail must not re-load RLS-protected rows. */
final class CohortAtRisk extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<array{title: string, detail: string}>  $problems */
    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $cohortId,
        public readonly string $cohort,
        public readonly array $problems,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Needs attention: {$this->cohort}")->greeting("Hello {$this->provider},")
            ->line("Something has changed that a running cohort, {$this->cohort}, relies on:");
        foreach ($this->problems as $p) {
            $mail->line("- **{$p['title']}**: {$p['detail']}");
        }

        return $mail->line('Fix it before it affects assessments or verification.')
            ->action('Open the cohort', 'https://'.$this->code.'.'.config('platform.base_domain').'/cohorts/'.$this->cohortId);
    }
}
