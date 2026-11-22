<?php

declare(strict_types=1);

namespace Modules\Onboarding\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Onboarding\Models\Application;
use Modules\Tenancy\Models\Tenant;

final class ApplicationDecided extends ApplicationMail
{
    public function __construct(Application $application, public readonly bool $approved, public readonly ?Tenant $tenant = null)
    {
        parent::__construct($application);
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->approved) {
            return (new MailMessage)
                ->subject("Welcome: {$this->application->legal_name} is approved")
                ->greeting("Congratulations, {$this->application->first_name}!")
                ->line('Your provider is live at https://'.$this->tenant?->code.'.'.config('platform.base_domain').'.')
                ->line('A separate email has your invitation to set your password and finish setting up.');
        }

        return (new MailMessage)
            ->subject("Your application {$this->application->reference}")
            ->greeting("Hello {$this->application->first_name},")
            ->line('We could not approve your application:')
            ->line((string) $this->application->decision_reason)
            ->line('You are welcome to apply again when this is resolved.');
    }
}
