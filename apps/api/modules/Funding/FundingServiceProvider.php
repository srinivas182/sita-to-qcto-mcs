<?php

declare(strict_types=1);

namespace Modules\Funding;

use Modules\Support\ModuleServiceProvider;

/** Grant agreements with funders (SETAs, employers), tranches and their milestones; later stipends and claims. */
final class FundingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void {}
}
