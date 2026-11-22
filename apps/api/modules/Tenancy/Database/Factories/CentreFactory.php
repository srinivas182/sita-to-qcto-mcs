<?php

declare(strict_types=1);

namespace Modules\Tenancy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;

/** @extends Factory<Centre> */
final class CentreFactory extends Factory
{
    protected $model = Centre::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->city().' Centre',
            'accreditation_number' => 'QCTO-SDP-'.$this->faker->numerify('######'),
            'accreditation_expires_on' => now()->addYears(3)->toDateString(),
            'status' => 'active',
        ];
    }
}
