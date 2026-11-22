<?php

declare(strict_types=1);

namespace Modules\Billing;

use Illuminate\Support\Facades\Schedule;
use Modules\Billing\Console\RunBilling;
use Modules\Billing\Gateways\Gateways;
use Modules\Billing\Gateways\GatewayTester;
use Modules\Settings\Services\IntegrationTesters;
use Modules\Support\ModuleServiceProvider;

/** Billing: packages, subscriptions, SARS tax invoices, payments and collections. */
final class BillingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'billing');

        $testers = $this->app->make(IntegrationTesters::class);
        foreach (array_keys(Gateways::ADAPTERS) as $driver) {
            $testers->register($driver, GatewayTester::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunBilling::class]);
        }

        Schedule::command('billing:run')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
    }
}
