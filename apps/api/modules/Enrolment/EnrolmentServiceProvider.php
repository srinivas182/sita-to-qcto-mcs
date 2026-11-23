<?php

declare(strict_types=1);

namespace Modules\Enrolment;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Modules\Agreements\Events\AgreementCompleted;
use Modules\Enrolment\Console\LapseOffers;
use Modules\Enrolment\Console\PurgeApplicants;
use Modules\Enrolment\Console\QctoDueAlerts;
use Modules\Enrolment\Console\RunIdentityChecks;
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
            $this->commands([LapseOffers::class, QctoDueAlerts::class, RunIdentityChecks::class, PurgeApplicants::class]);
        }
        Schedule::command('enrolment:lapse-offers')->hourly()->onOneServer();
        Schedule::command('enrolment:qcto-due')->dailyAt('07:10')->onOneServer();
        Schedule::command('identity:run-checks')->dailyAt('01:30')->onOneServer(); // off-peak, one batch
        Schedule::command('enrolment:purge-applicants')->dailyAt('03:10')->onOneServer();
    }
}
