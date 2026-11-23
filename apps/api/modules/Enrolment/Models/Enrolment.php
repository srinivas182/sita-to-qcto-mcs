<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $cohort_id
 * @property string|null $application_id
 * @property string|null $agreement_id
 * @property string $status
 * @property string|null $funding
 * @property Carbon $enrolled_on
 * @property Carbon|null $ended_on
 * @property string|null $reason
 */
final class Enrolment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['user_id', 'cohort_id', 'application_id', 'agreement_id', 'funding', 'enrolled_on'];

    protected function casts(): array
    {
        return ['enrolled_on' => 'date', 'ended_on' => 'date'];
    }
}
