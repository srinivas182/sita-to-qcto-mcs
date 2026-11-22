<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Tenancy\Models\Tenant;

/** @mixin Tenant */
final class TenantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Tenant $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'code' => $t->code,
            'legal_name' => $t->legal_name,
            'trading_name' => $t->trading_name,
            'registration_number' => $t->registration_number,
            'status' => $t->status->value,
            'cell_id' => $t->cell_id,
            'package_code' => $t->package_code,
            'branding' => $t->branding ?? (object) [],
            'approved_at' => $t->approved_at?->toIso8601String(),
            'suspended_at' => $t->suspended_at?->toIso8601String(),
            'suspension_reason' => $t->suspension_reason,
            'created_at' => $t->created_at?->toIso8601String(),
            'domains' => DomainResource::collection($this->whenLoaded('domains')),
            'centres_count' => $this->whenCounted('centres'),
        ];
    }
}
