<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $task_id
 * @property string $user_id
 * @property string $enrolment_id
 * @property int $number
 * @property array<string, mixed> $answers
 * @property list<string> $file_ids
 * @property int $auto_marks
 * @property int|null $marks
 * @property int $out_of
 * @property int|null $score_pct
 * @property string $status
 * @property string|null $feedback
 * @property string|null $marked_by
 * @property Carbon|null $marked_at
 * @property Carbon $submitted_at
 * @property bool $offline
 */
final class TaskAttempt extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'submitted', 'auto_marks' => 0, 'offline' => false];

    protected $fillable = ['task_id', 'user_id', 'enrolment_id', 'number', 'answers', 'file_ids', 'auto_marks', 'out_of', 'submitted_at', 'offline'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'file_ids' => 'array', 'marked_at' => 'datetime', 'submitted_at' => 'datetime', 'offline' => 'boolean',
            'auto_marks' => 'integer', 'marks' => 'integer', 'out_of' => 'integer', 'score_pct' => 'integer', 'number' => 'integer'];
    }
}
