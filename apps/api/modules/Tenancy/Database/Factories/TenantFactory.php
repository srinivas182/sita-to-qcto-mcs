<?php

declare(strict_types=1);

namespace Modules\Tenancy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenancy\Enums\TenantStatus;
use Modules\Tenancy\Models\Cell;
use Modules\Tenancy\Models\Tenant;

/**
 * Creates tenants directly (no subdomain, no lifecycle). Use TenantService::register
 * in tests that exercise onboarding. Must run in the platform context.
 *
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'cell_id' => Cell::factory(),
            'code' => substr('tp'.$this->faker->unique()->numerify('######'), 0, 30),
            'legal_name' => $name.' (Pty) Ltd',
            'trading_name' => $name,
            'status' => TenantStatus::Active,
        ];
    }

    public function status(TenantStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
