<?php

declare(strict_types=1);

namespace Modules\Delivery;

use Modules\Support\ModuleServiceProvider;

/** Delivery: timetables (class sessions), attendance, registers, materials and formative tasks. */
final class DeliveryServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void {}
}
