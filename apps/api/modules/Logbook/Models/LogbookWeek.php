<?php

declare(strict_types=1);

namespace Modules\Logbook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $user_id
 * @property string $learner_placement_id
 * @property Carbon $week_start
 * @property string $status
 * @property string|null $signed_by
 * @property string|null $mentor_id
 * @property Carbon|null $signed_at
 * @property string|null $signature
 * @property string|null $comment
 * @property Carbon|null $reminded_at
 * @property Carbon|null $updated_at
 */
final class LogbookWeek extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['user_id', 'learner_placement_id', 'week_start'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'signed_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
