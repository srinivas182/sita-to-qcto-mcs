<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $user_id
 * @property string $cohort_id
 * @property string $number
 * @property string $issued_by
 * @property Carbon $issued_at
 * @property array<string, mixed> $snapshot
 * @property string $verify_code
 * @property string $hash
 * @property string $status
 * @property string|null $withdrawn_reason
 * @property string|null $withdrawn_by
 * @property Carbon|null $withdrawn_at
 */
final class StatementOfResults extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'statements_of_results';

    protected $attributes = ['status' => 'issued'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'issued_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
