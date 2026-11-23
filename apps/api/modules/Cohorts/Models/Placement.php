<?php

declare(strict_types=1);

namespace Modules\Cohorts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * Workplace places a cohort plans at one approved site.
 *
 * @property string $id
 * @property string $cohort_id
 * @property string $employer_id
 * @property string $site_id
 * @property string|null $mentor_id
 * @property int $planned_learners
 */
final class Placement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'cohort_placements';

    protected $fillable = ['cohort_id', 'employer_id', 'site_id', 'mentor_id', 'planned_learners'];

    protected function casts(): array
    {
        return ['planned_learners' => 'integer'];
    }
}
