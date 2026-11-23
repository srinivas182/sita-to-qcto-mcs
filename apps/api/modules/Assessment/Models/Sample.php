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
 * @property string $cohort_id
 * @property string $module_id
 * @property string $assessor_id
 * @property string $reason
 * @property string $status
 * @property string|null $moderator_id
 * @property string|null $decision
 * @property string|null $comment
 * @property string|null $new_result_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 */
final class Sample extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'moderation_samples';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
