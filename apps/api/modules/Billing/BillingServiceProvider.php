<?php

declare(strict_types=1);

namespace Modules\Billing;

use Illuminate\Support\Facades\Schedule;
use Modules\Billing\Console\RunBilling;
use Modules\Billing\Gateways\Gateways;
use Modules\Billing\Gateways\GatewayTester;
use Modules\Settings\Services\IntegrationCallbacks;
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
        // Only gateways with a safe, read-only check get a connection test.
        foreach (Gateways::ADAPTERS as $driver => $class) {
            if (method_exists($class, 'ping')) {
                $testers->register($driver, GatewayTester::class);
            }
        }

        $callbacks = $this->app->make(IntegrationCallbacks::class);
        foreach (array_keys(Gateways::ADAPTERS) as $driver) {
            $callbacks->register($driver, fn () => Gateways::webhookUrl($driver));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunBilling::class]);
        }

        Schedule::command('billing:run')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
    }
}
