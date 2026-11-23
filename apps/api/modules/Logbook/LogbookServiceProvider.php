<?php

declare(strict_types=1);

namespace Modules\Logbook;

use Modules\Support\ModuleServiceProvider;

/** The workplace logbook: rules per work experience module, entries from site, mentor sign-off. */
final class LogbookServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void {}
}
