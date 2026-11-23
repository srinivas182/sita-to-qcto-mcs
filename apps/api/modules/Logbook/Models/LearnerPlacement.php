<?php

declare(strict_types=1);

namespace Modules\Logbook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $enrolment_id
 * @property string $user_id
 * @property string $cohort_id
 * @property string $site_id
 * @property string|null $mentor_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
final class LearnerPlacement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['enrolment_id', 'user_id', 'cohort_id', 'site_id', 'mentor_id', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function covers(string $date): bool
    {
        return $date >= $this->starts_on->toDateString() && ($this->ends_on === null || $date <= $this->ends_on->toDateString());
    }
}
