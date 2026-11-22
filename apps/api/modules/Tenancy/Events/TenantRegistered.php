<?php

declare(strict_types=1);

namespace Modules\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Tenancy\Models\Tenant;

final class TenantRegistered
{
    use Dispatchable;

    public function __construct(public readonly Tenant $tenant) {}
}
