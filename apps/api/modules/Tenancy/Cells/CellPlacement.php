<?php

declare(strict_types=1);

namespace Modules\Tenancy\Cells;

use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Enums\CellStatus;
use Modules\Tenancy\Exceptions\TenantError;
use Modules\Tenancy\Models\Cell;

/**
 * Chooses the cell for a new tenant: the least-loaded active, shared cell with spare
 * capacity. Dedicated cells (large clients) are only used when chosen explicitly.
 */
final class CellPlacement
{
    public function __construct(private readonly TenantContext $context) {}

    public function choose(): Cell
    {
        $cell = $this->context->runAsPlatform(fn (): ?Cell => Cell::query()
            ->where('status', CellStatus::Active)
            ->where('dedicated', false)
            ->withCount('tenants')
            ->get()
            ->filter(fn (Cell $c): bool => $this->load($c) < $c->capacity_tenants)
            ->sortBy(fn (Cell $c): float => $this->load($c) / max(1, $c->capacity_tenants))
            ->first());

        if ($cell === null) {
            throw TenantError::noCellCapacity();
        }

        return $cell;
    }

    /** @return list<array{id: string, name: string, region: string, status: string, dedicated: bool, tenants: int, capacity: int, load: float}> */
    public function overview(): array
    {
        return $this->context->runAsPlatform(fn (): array => array_values(Cell::query()
            ->withCount('tenants')
            ->orderBy('id')
            ->get()
            ->map(fn (Cell $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'region' => $c->region,
                'status' => $c->status->value,
                'dedicated' => $c->dedicated,
                'tenants' => $this->load($c),
                'capacity' => $c->capacity_tenants,
                'load' => round($this->load($c) / max(1, $c->capacity_tenants), 4),
            ])
            ->all()));
    }

    private function load(Cell $cell): int
    {
        $count = $cell->getAttribute('tenants_count');

        return is_numeric($count) ? (int) $count : 0;
    }
}
