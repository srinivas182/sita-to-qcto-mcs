<?php

declare(strict_types=1);

namespace Modules\Assessment;

use Illuminate\Support\Facades\Schedule;
use Modules\Assessment\Console\EisaClosingAlerts;
use Modules\Support\ModuleServiceProvider;

/** Internal summative assessment, moderation, appeals and the Statement of Results. */
final class AssessmentServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'assessment');
        if ($this->app->runningInConsole()) {
            $this->commands([EisaClosingAlerts::class]);
        }
        Schedule::command('eisa:closing')->dailyAt('07:25')->onOneServer();
    }
}
