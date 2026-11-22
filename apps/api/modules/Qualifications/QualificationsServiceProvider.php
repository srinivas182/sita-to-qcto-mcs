<?php

declare(strict_types=1);

namespace Modules\Qualifications;

use Illuminate\Support\Facades\Schedule;
use Modules\Qualifications\Console\EnrolmentClosingAlerts;
use Modules\Support\ModuleServiceProvider;

/** The shared qualification catalogue and what each provider offers. */
final class QualificationsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnrolmentClosingAlerts::class]);
        }
        Schedule::command('qualifications:enrolment-alerts')->dailyAt('06:20')->onOneServer();
    }
}
