<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Audit\AuditServiceProvider;
use Modules\Platform\PlatformServiceProvider;
use Modules\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    TenancyServiceProvider::class,
    AuditServiceProvider::class,
];
