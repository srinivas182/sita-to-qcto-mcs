<?php

declare(strict_types=1);

namespace Modules\Delivery;

use Illuminate\Support\Facades\Schedule;
use Modules\Delivery\Console\Registers;
use Modules\Delivery\Console\WatchAttendance;
use Modules\Support\ModuleServiceProvider;

/** Delivery: timetables (class sessions), attendance, registers, materials and formative tasks. */
final class DeliveryServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'delivery');
        if ($this->app->runningInConsole()) {
            $this->commands([Registers::class, WatchAttendance::class]);
        }
        Schedule::command('delivery:registers')->dailyAt('06:20')->onOneServer();
        Schedule::command('delivery:attendance-watch')->dailyAt('18:10')->onOneServer();
    }
}
