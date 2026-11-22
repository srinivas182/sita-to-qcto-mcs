<?php

declare(strict_types=1);

namespace Modules\Qualifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A qualification a provider offers is closing (or has closed) for new enrolments. */
final class EnrolmentClosing extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $successors */
    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $title,
        public readonly string $lastEnrolment,
        public readonly string $lastAchievement,
        public readonly int $days,
        public readonly array $successors,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->days < 0 ? "Enrolment has closed: {$this->title}" : "Enrolment closes in {$this->days} days: {$this->title}")
            ->greeting("Hello {$this->provider},")
            ->line($this->days < 0
                ? "No new learners may be enrolled on {$this->title} since {$this->lastEnrolment}."
                : "The last date to enrol new learners on {$this->title} is {$this->lastEnrolment}.")
            ->line($this->lastAchievement !== '' ? "Learners already enrolled must finish by {$this->lastAchievement}." : '');

        if ($this->successors !== []) {
            $mail->line('It is replaced by: '.implode('; ', $this->successors).'. You can add these from the catalogue.');
        }

        return $mail->action('Open programmes', 'https://'.$this->code.'.'.config('platform.base_domain').'/programmes');
    }
}
