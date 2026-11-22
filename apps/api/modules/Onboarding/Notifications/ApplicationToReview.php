<?php

declare(strict_types=1);

namespace Modules\Onboarding\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class ApplicationToReview extends ApplicationMail
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New provider application: {$this->application->legal_name}")
            ->line("{$this->application->legal_name} ({$this->application->reference}) applied to join. Automatic checks have passed.")
            ->action('Review the application', 'https://admin.'.config('platform.base_domain').'/applications/'.$this->application->id);
    }
}
