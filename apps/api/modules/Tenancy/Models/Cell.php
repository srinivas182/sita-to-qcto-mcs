<?php

declare(strict_types=1);

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tenancy\Database\Factories\CellFactory;
use Modules\Tenancy\Enums\CellStatus;

/**
 * @property string $id
 * @property string $name
 * @property string $region
 * @property CellStatus $status
 * @property int $capacity_tenants
 * @property bool $dedicated
 */
class Cell extends Model
{
    /** @use HasFactory<CellFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'region', 'status', 'capacity_tenants', 'dedicated'];

    protected function casts(): array
    {
        return [
            'status' => CellStatus::class,
            'capacity_tenants' => 'integer',
            'dedicated' => 'boolean',
        ];
    }

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    protected static function newFactory(): CellFactory
    {
        return CellFactory::new();
    }
}
