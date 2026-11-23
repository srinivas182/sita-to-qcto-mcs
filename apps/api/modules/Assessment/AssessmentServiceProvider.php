<?php

declare(strict_types=1);

namespace Modules\Assessment;

use Modules\Support\ModuleServiceProvider;

/** Internal summative assessment, moderation, appeals and the Statement of Results. */
final class AssessmentServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'assessment');
    }
}
