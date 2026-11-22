<?php

declare(strict_types=1);

namespace Modules\Onboarding\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Onboarding\Models\Application;

final class ApplicationNeedsInfo extends ApplicationMail
{
    public function __construct(Application $application, public readonly string $request)
    {
        parent::__construct($application);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Please update your application {$this->application->reference}")
            ->greeting("Hello {$this->application->first_name},")
            ->line('Before we can approve your application, we need the following:')
            ->line($this->request)
            ->action('Update your application', $this->resumeUrl());
    }
}
