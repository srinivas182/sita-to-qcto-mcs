<?php

declare(strict_types=1);

namespace Modules\Logbook;

use Illuminate\Support\Facades\Schedule;
use Modules\Delivery\Contracts\WorkplaceDays;
use Modules\Logbook\Console\WatchLogbooks;
use Modules\Logbook\Support\LogbookWorkplaceDays;
use Modules\Support\ModuleServiceProvider;

/** The workplace logbook: rules per work experience module, entries from site, mentor sign-off. */
final class LogbookServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->app->bind(WorkplaceDays::class, LogbookWorkplaceDays::class);
        if ($this->app->runningInConsole()) {
            $this->commands([WatchLogbooks::class]);
        }
        Schedule::command('logbook:watch')->dailyAt('17:40')->onOneServer();
    }
}
