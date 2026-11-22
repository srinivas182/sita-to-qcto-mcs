<?php

declare(strict_types=1);

namespace Modules\Tenancy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenancy\Enums\CellStatus;
use Modules\Tenancy\Models\Cell;

/** @extends Factory<Cell> */
final class CellFactory extends Factory
{
    protected $model = Cell::class;

    public function definition(): array
    {
        return [
            'id' => 'cell-'.$this->faker->unique()->numberBetween(2, 99999),
            'name' => 'Cell '.$this->faker->city(),
            'region' => 'af-south-1',
            'status' => CellStatus::Active,
            'capacity_tenants' => 500,
            'dedicated' => false,
        ];
    }
}
