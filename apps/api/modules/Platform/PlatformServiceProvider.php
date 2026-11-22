<?php

declare(strict_types=1);

namespace Modules\Platform;

use Modules\Support\ModuleServiceProvider;

final class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }
}
