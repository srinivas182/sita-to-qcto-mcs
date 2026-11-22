<?php

declare(strict_types=1);

namespace Modules\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Tenancy\Context\TenantContext;

/**
 * Application-level twin of the database policy: tenant-owned models are filtered
 * to the current tenant. In the platform context nothing is filtered; with no
 * context the query returns nothing. RLS remains the final guarantee.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /** @param  Builder<covariant Model>  $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatform()) {
            return;
        }

        $tenantId = $context->tenantId();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
