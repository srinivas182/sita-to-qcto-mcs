<?php

declare(strict_types=1);

namespace Modules\Onboarding;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Support\ModuleServiceProvider;

/** Onboarding: applications from training providers, review and approval. */
final class OnboardingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        // New applications and resume codes: a few per hour per address.
        RateLimiter::for('applications', fn (Request $request) => [
            Limit::perHour(10)->by('applications:'.$request->ip()),
        ]);
    }
}
