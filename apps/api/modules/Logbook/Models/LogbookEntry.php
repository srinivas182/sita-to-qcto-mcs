<?php

declare(strict_types=1);

namespace Modules\Logbook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $learner_placement_id
 * @property string $module_id
 * @property string $activity_key
 * @property Carbon $work_date
 * @property Carbon $week_start
 * @property int $minutes
 * @property string|null $note
 * @property list<string> $file_ids
 * @property list<string> $photo_hashes
 * @property int|null $distance_m
 * @property bool|null $location_ok
 * @property bool $offline
 * @property Carbon $device_time
 * @property string $device_time_text
 * @property string $client_id
 * @property int $seq
 * @property string|null $prev_hash
 * @property string $hash
 * @property bool $rechained
 * @property string $status
 * @property string|null $replaces_id
 * @property string|null $comment
 * @property Carbon|null $created_at
 */
final class LogbookEntry extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Entries that count towards hours (not replaced by a correction). */
    public const COUNTED = ['submitted', 'signed'];

    protected $attributes = ['status' => 'submitted', 'offline' => false, 'rechained' => false];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'week_start' => 'date', 'device_time' => 'datetime', 'file_ids' => 'array', 'photo_hashes' => 'array',
            'offline' => 'boolean', 'location_ok' => 'boolean', 'rechained' => 'boolean', 'minutes' => 'integer', 'seq' => 'integer', 'distance_m' => 'integer'];
    }
}
