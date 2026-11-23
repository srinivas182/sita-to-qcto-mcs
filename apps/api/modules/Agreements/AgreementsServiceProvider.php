<?php

declare(strict_types=1);

namespace Modules\Agreements;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schedule;
use Modules\Agreements\Console\FollowUp;
use Modules\Agreements\Signing\LawtrustTester;
use Modules\Settings\Services\IntegrationTesters;
use Modules\Support\ModuleServiceProvider;

/** Agreement templates, sending, PIN-confirmed signing, sealing and verification. */
final class AgreementsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'agreements');
        $this->app->make(IntegrationTesters::class)->register('esign.lawtrust', LawtrustTester::class);
        RateLimiter::for('signing', fn (Request $request) => [Limit::perMinute(60)->by('sign-ip:'.$request->ip())]);
        if ($this->app->runningInConsole()) {
            $this->commands([FollowUp::class]);
        }
        Schedule::command('agreements:follow-up')->dailyAt('08:10')->onOneServer();
        Schedule::command('agreements:follow-up --advanced')->everyFifteenMinutes()->onOneServer();
    }
}
