<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $result_id
 * @property string $user_id
 * @property string $cohort_id
 * @property string $reason
 * @property Carbon $lodged_at
 * @property Carbon $decide_by
 * @property string $status
 * @property string|null $decided_by
 * @property string|null $decision_note
 * @property string|null $new_result_id
 * @property Carbon|null $decided_at
 */
final class Appeal extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'assessment_appeals';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['lodged_at' => 'datetime', 'decide_by' => 'date', 'decided_at' => 'datetime'];
    }
}
