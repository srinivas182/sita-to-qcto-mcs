<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Counts active learners: people with an active membership and the learner role.
 * Runs in the tenant's context, so RLS limits it to that provider.
 */
final class LearnerMeter
{
    public function __construct(private readonly TenantContext $context) {}

    public function activeLearners(Tenant $tenant): int
    {
        return (int) $this->context->runAs($tenant, fn () => DB::table('role_assignments as r')
            ->join('user_tenants as m', fn ($j) => $j->on('m.user_id', '=', 'r.user_id')->on('m.tenant_id', '=', 'r.tenant_id'))
            ->where('r.role', 'learner')
            ->where('m.status', 'active')
            ->distinct()
            ->count('r.user_id'));
    }
}
