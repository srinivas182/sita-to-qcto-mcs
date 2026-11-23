<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $material_id
 * @property string $user_id
 * @property Carbon|null $opened_at
 * @property Carbon|null $completed_at
 */
final class MaterialProgress extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'material_progress';

    protected $fillable = ['material_id', 'user_id'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
