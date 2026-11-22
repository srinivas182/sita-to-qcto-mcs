<?php

declare(strict_types=1);

namespace Modules\Onboarding\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class ApplicationReceived extends ApplicationMail
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("We received your application {$this->application->reference}")
            ->greeting("Thank you, {$this->application->first_name}.")
            ->line("We are checking {$this->application->legal_name}'s accreditation and documents. You will hear from us by email, usually within two working days.")
            ->action('See your application', $this->resumeUrl());
    }
}
