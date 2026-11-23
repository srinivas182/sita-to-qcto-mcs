<?php

declare(strict_types=1);

namespace Modules\Enrolment;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Modules\Agreements\Events\AgreementCompleted;
use Modules\Enrolment\Console\LapseOffers;
use Modules\Enrolment\Services\EnrolmentService;
use Modules\Support\ModuleServiceProvider;

/** Intakes, applications and (later in Sprint 4) documents, offers, consent and enrolments. */
final class EnrolmentServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        Event::listen(AgreementCompleted::class, [EnrolmentService::class, 'onAgreementCompleted']);
        if ($this->app->runningInConsole()) {
            $this->commands([LapseOffers::class]);
        }
        Schedule::command('enrolment:lapse-offers')->hourly()->onOneServer();
    }
}
