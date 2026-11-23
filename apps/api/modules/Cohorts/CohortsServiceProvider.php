<?php

declare(strict_types=1);

namespace Modules\Cohorts;

use Illuminate\Support\Facades\Schedule;
use Modules\Cohorts\Console\WatchCohorts;
use Modules\Support\ModuleServiceProvider;

/** Cohorts: planning, the readiness check, opening, and watching open cohorts. */
final class CohortsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WatchCohorts::class]);
        }
        Schedule::command('cohorts:watch')->dailyAt('06:50')->onOneServer();
    }
}
