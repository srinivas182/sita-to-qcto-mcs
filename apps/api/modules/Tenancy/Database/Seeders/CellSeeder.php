<?php

declare(strict_types=1);

namespace Modules\Tenancy\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenancy\Enums\CellStatus;
use Modules\Tenancy\Models\Cell;

/** Ensures the cell this deployment serves exists (idempotent; safe in production). */
final class CellSeeder extends Seeder
{
    public function run(): void
    {
        Cell::query()->firstOrCreate(
            ['id' => (string) config('platform.cell')],
            ['name' => 'South Africa 1', 'region' => 'af-south-1', 'status' => CellStatus::Active, 'capacity_tenants' => 500],
        );
    }
}
