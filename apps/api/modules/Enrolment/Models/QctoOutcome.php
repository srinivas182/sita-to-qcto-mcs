<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $submission_id
 * @property string $user_id
 * @property string $status
 * @property string|null $reason
 * @property string|null $loaded_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 */
final class QctoOutcome extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
