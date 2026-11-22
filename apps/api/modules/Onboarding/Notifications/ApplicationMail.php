<?php

declare(strict_types=1);

namespace Modules\Onboarding\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Onboarding\Models\Application;

abstract class ApplicationMail extends Notification implements ShouldQueue
{
    use Queueable;

    public ApplicationSummary $application;

    public function __construct(Application $application)
    {
        $this->application = ApplicationSummary::of($application);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Resume links carry only the reference; the applicant confirms with an emailed code. */
    protected function resumeUrl(): string
    {
        return 'https://'.config('platform.base_domain').'/apply/resume?ref='.$this->application->reference;
    }
}
