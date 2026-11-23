<?php

declare(strict_types=1);

namespace Modules\Cohorts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $cohort_id
 * @property string $user_id
 * @property string $role
 */
final class CohortStaff extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const ROLES = ['facilitator', 'assessor', 'moderator'];

    protected $table = 'cohort_staff';

    protected $fillable = ['cohort_id', 'user_id', 'role'];
}
