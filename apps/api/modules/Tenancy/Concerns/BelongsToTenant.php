<?php

declare(strict_types=1);

namespace Modules\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Exceptions\CrossTenantWrite;
use Modules\Tenancy\Scopes\TenantScope;

/**
 * For models owned by a tenant (table has a tenant_id column protected by RLS).
 *
 * - Reads are scoped to the current tenant.
 * - New rows take the current tenant automatically.
 * - Writing a row for another tenant throws before reaching the database.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function (Model $model): void {
            $context = app(TenantContext::class);
            $current = $context->tenantId();
            $given = $model->getAttribute('tenant_id');

            if ($given === null && $current !== null) {
                $model->setAttribute('tenant_id', $current);

                return;
            }

            if ($context->isPlatform()) {
                return;
            }

            if ($given !== $current) {
                throw CrossTenantWrite::for($model::class, $current, is_string($given) ? $given : null);
            }
        });
    }

    public function auditTenantId(): ?string
    {
        $value = $this->getAttribute('tenant_id');

        return is_string($value) ? $value : null;
    }
}
