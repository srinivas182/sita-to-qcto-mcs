<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Access\AccessServiceProvider;
use Modules\Audit\AuditServiceProvider;
use Modules\Identity\IdentityServiceProvider;
use Modules\Platform\PlatformServiceProvider;
use Modules\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    TenancyServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    AccessServiceProvider::class,
];
