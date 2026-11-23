<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $cohort_id
 * @property string|null $module_id
 * @property string $kind
 * @property string|null $title
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $room_id
 * @property string|null $venue
 * @property string|null $facilitator_id
 * @property string $status
 * @property string|null $cancel_reason
 * @property string|null $series_id
 * @property string|null $notes
 * @property string|null $created_by
 */
final class ClassSession extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const KINDS = ['knowledge' => 'Knowledge', 'practical' => 'Practical'];

    protected $attributes = ['status' => 'scheduled'];

    protected $fillable = ['cohort_id', 'module_id', 'kind', 'title', 'starts_at', 'ends_at', 'room_id', 'venue', 'facilitator_id', 'series_id', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function minutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
