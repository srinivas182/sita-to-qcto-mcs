<?php

declare(strict_types=1);

namespace Modules\Settings;

use Modules\Settings\Services\IntegrationCallbacks;
use Modules\Settings\Services\IntegrationCatalogue;
use Modules\Settings\Services\IntegrationTesters;
use Modules\Support\ModuleServiceProvider;

/**
 * Platform settings: company details (printed on tax invoices) and the integrations
 * catalogue (payment, SMS, WhatsApp, email and storage drivers with encrypted keys).
 */
final class SettingsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntegrationCatalogue::class, fn (): IntegrationCatalogue => new IntegrationCatalogue((array) config('integrations')));
        // Modules that implement a type register a tester per driver at boot.
        $this->app->singleton(IntegrationTesters::class);
        $this->app->singleton(IntegrationCallbacks::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
