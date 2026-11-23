<?php

declare(strict_types=1);

namespace Modules\Cohorts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A group of learners taking one qualification at one centre, from intake to planned end.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $qualification_id
 * @property string $centre_id
 * @property string $mode
 * @property Carbon $intake_on
 * @property Carbon $planned_end_on
 * @property int $capacity
 * @property string $status
 * @property string|null $notes
 * @property array<string, mixed>|null $readiness
 * @property list<string>|null $watch_fails
 * @property Carbon|null $opened_at
 * @property string|null $opened_by
 * @property Carbon $updated_at
 */
final class Cohort extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id', 'status', 'readiness', 'watch_fails', 'opened_at', 'opened_by'];

    protected function casts(): array
    {
        return ['intake_on' => 'date', 'planned_end_on' => 'date', 'capacity' => 'integer', 'readiness' => 'array', 'watch_fails' => 'array', 'opened_at' => 'datetime'];
    }

    /** @return HasMany<CohortStaff, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(CohortStaff::class);
    }

    /** @return HasMany<Placement, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }
}
