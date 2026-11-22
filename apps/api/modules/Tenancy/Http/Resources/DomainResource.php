<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Tenancy\Domains\DomainService;
use Modules\Tenancy\Models\TenantDomain;

/** @mixin TenantDomain */
final class DomainResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantDomain $d */
        $d = $this->resource;

        return [
            'id' => $d->id,
            'hostname' => $d->hostname,
            'type' => $d->type->value,
            'dns_status' => $d->dns_status->value,
            'ssl_status' => $d->ssl_status->value,
            'is_primary' => $d->is_primary,
            'verified_at' => $d->verified_at?->toIso8601String(),
            'last_checked_at' => $d->last_checked_at?->toIso8601String(),
            'records' => $d->type->value === 'custom'
                ? ($d->last_check['records'] ?? app(DomainService::class)->instructions($d)->jsonSerialize()['records'])
                : [],
        ];
    }
}
