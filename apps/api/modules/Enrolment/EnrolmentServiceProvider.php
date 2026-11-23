<?php

declare(strict_types=1);

namespace Modules\Enrolment;

use Modules\Support\ModuleServiceProvider;

/** Intakes, applications and (later in Sprint 4) documents, offers, consent and enrolments. */
final class EnrolmentServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void {}
}
