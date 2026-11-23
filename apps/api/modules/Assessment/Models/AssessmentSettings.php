<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $max_attempts
 * @property int $random_pct
 * @property int $new_assessor_first
 * @property int $appeal_days
 * @property int $appeal_decide_days
 */
final class AssessmentSettings extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['max_attempts' => 3, 'random_pct' => 25, 'new_assessor_first' => 5, 'appeal_days' => 14, 'appeal_decide_days' => 21];

    protected $fillable = ['max_attempts', 'random_pct', 'new_assessor_first', 'appeal_days', 'appeal_decide_days'];

    protected function casts(): array
    {
        return ['max_attempts' => 'integer', 'random_pct' => 'integer', 'new_assessor_first' => 'integer', 'appeal_days' => 'integer', 'appeal_decide_days' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }
}
