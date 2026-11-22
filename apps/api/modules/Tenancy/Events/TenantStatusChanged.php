<?php

declare(strict_types=1);

namespace Modules\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Tenancy\Enums\TenantStatus;
use Modules\Tenancy\Models\Tenant;

final class TenantStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantStatus $from,
        public readonly TenantStatus $to,
    ) {}
}
