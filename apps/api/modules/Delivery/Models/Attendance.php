<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $session_id
 * @property string $user_id
 * @property string $status
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $checked_out_at
 * @property int $minutes_late
 * @property string $method
 * @property bool $offline
 * @property int|null $distance_m
 * @property bool|null $location_ok
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property string|null $device_id
 * @property string|null $marked_by
 * @property string|null $reason
 * @property string|null $note_file_id
 */
final class Attendance extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const STATUSES = ['present' => 'Present', 'late' => 'Late', 'left_early' => 'Left early', 'absent' => 'Absent', 'excused' => 'Excused'];

    /** Statuses that count as attended. */
    public const ATTENDED = ['present', 'late', 'left_early'];

    protected $attributes = ['minutes_late' => 0, 'offline' => false, 'needs_review' => false];

    protected $fillable = ['session_id', 'user_id', 'status', 'checked_in_at', 'checked_out_at', 'minutes_late', 'method', 'offline', 'distance_m',
        'location_ok', 'needs_review', 'review_reason', 'device_id', 'marked_by', 'reason', 'note_file_id'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'offline' => 'boolean', 'location_ok' => 'boolean',
            'needs_review' => 'boolean', 'minutes_late' => 'integer', 'distance_m' => 'integer'];
    }
}
