<?php

declare(strict_types=1);

namespace Modules\People;

use Illuminate\Support\Facades\Schedule;
use Modules\People\Console\CredentialExpiryAlerts;
use Modules\Support\ModuleServiceProvider;

/** Profiles, the staff credential register and bulk import. */
final class PeopleServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CredentialExpiryAlerts::class]);
        }
        Schedule::command('credentials:expiry-alerts')->dailyAt('06:30')->onOneServer();
    }
}
