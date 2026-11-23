<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $centre_id
 * @property string $name
 * @property int|null $capacity
 * @property string $kind
 * @property bool $active
 */
final class Room extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const KINDS = ['classroom' => 'Classroom', 'workshop' => 'Workshop', 'computer_lab' => 'Computer lab', 'outdoor' => 'Outdoor or yard'];

    protected $attributes = ['kind' => 'classroom', 'active' => true];

    protected $fillable = ['centre_id', 'name', 'capacity', 'kind', 'active'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'active' => 'boolean'];
    }
}
