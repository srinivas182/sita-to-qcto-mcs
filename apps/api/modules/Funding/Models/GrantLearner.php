<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $agreement_id
 * @property string $enrolment_id
 * @property bool $disabled
 * @property string $status
 * @property string|null $removed_reason
 * @property Carbon|null $removed_at
 */
final class GrantLearner extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'active', 'disabled' => false];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['disabled' => 'boolean', 'removed_at' => 'datetime'];
    }
}
