<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $run_id
 * @property string $enrolment_id
 * @property string $user_id
 * @property int|null $attendance_pct
 * @property int $proposed_cents
 * @property int $amount_cents
 * @property string $status
 * @property string|null $reason
 * @property string|null $override_reason
 */
final class StipendLine extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['attendance_pct' => 'integer', 'proposed_cents' => 'integer', 'amount_cents' => 'integer'];
    }
}
