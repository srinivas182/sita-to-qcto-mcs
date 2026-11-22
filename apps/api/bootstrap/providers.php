<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Access\AccessServiceProvider;
use Modules\Audit\AuditServiceProvider;
use Modules\Billing\BillingServiceProvider;
use Modules\Files\FilesServiceProvider;
use Modules\Identity\IdentityServiceProvider;
use Modules\Messaging\MessagingServiceProvider;
use Modules\Onboarding\OnboardingServiceProvider;
use Modules\Platform\PlatformServiceProvider;
use Modules\Settings\SettingsServiceProvider;
use Modules\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    TenancyServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    AccessServiceProvider::class,
    SettingsServiceProvider::class,
    FilesServiceProvider::class,
    BillingServiceProvider::class,
    MessagingServiceProvider::class,
    OnboardingServiceProvider::class,
];
