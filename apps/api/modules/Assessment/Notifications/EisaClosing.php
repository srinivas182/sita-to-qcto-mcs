<?php

declare(strict_types=1);

namespace Modules\Assessment\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Plain values only: queued mail must not re-load RLS-protected rows. */
final class EisaClosing extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $cohortId,
        public readonly string $cohort,
        public readonly string $eisaOn,
        public readonly string $closesOn,
        public readonly int $days,
        public readonly int $waiting,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("EISA registration closes in {$this->days} days: {$this->cohort}")
            ->greeting("Hello {$this->provider},")
            ->line("Registration for the EISA on {$this->eisaOn} closes on {$this->closesOn}. {$this->waiting} learners with a Statement of Results are not registered yet.")
            ->line('The QCTO allows no late registrations: learners not registered in time move to the next EISA date.')
            ->action('Open EISA registration', 'https://'.$this->code.'.'.config('platform.base_domain').'/cohorts/'.$this->cohortId.'/eisa');
    }
}
