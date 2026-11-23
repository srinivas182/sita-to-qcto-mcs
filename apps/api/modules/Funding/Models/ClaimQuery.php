<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $claim_id
 * @property string|null $enrolment_id
 * @property string $problem
 * @property Carbon $raised_on
 * @property string|null $resolution
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 */
final class ClaimQuery extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['raised_on' => 'date', 'resolved_at' => 'datetime'];
    }
}
