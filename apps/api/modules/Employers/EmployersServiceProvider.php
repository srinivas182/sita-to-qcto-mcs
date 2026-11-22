<?php

declare(strict_types=1);

namespace Modules\Employers;

use Illuminate\Support\Facades\Schedule;
use Modules\Employers\Console\ApprovalExpiryAlerts;
use Modules\Support\ModuleServiceProvider;

/** Host employers, workplace sites, mentors and capacity. */
final class EmployersServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ApprovalExpiryAlerts::class]);
        }
        Schedule::command('employers:approval-alerts')->dailyAt('06:40')->onOneServer();
    }
}
