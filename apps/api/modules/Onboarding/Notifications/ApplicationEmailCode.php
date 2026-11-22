<?php

declare(strict_types=1);

namespace Modules\Onboarding\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Onboarding\Models\Application;

final class ApplicationEmailCode extends ApplicationMail
{
    public function __construct(Application $application, public readonly string $code)
    {
        parent::__construct($application);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} is your application code")
            ->greeting("Hello {$this->application->first_name},")
            ->line("Use this code to confirm your email address for application {$this->application->reference}:")
            ->line("**{$this->code}**")
            ->line('It expires in 30 minutes. If you did not start an application, you can ignore this email.');
    }
}
