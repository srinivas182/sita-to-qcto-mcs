<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $enrolment_id
 * @property string $user_id
 * @property string $cohort_id
 * @property Carbon $eisa_on
 * @property Carbon $closes_on
 * @property string|null $assessment_centre
 * @property string|null $assessment_centre_name
 * @property string|null $flc_code
 * @property string|null $flc_number
 * @property string|null $flc_file_id
 * @property Carbon|null $declared_at
 * @property string|null $declared_name
 * @property string|null $declaration_version
 * @property string $status
 * @property Carbon|null $registered_at
 */
final class EisaRegistration extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'eisa_registrations';

    protected $attributes = ['status' => 'draft'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['eisa_on' => 'date', 'closes_on' => 'date', 'declared_at' => 'datetime', 'registered_at' => 'datetime'];
    }
}
