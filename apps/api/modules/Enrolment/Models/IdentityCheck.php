<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $user_id
 * @property string $status
 * @property string|null $driver
 * @property array<string, mixed>|null $result
 * @property string|null $reason
 * @property int $attempts
 * @property int|null $cost_cents
 * @property string|null $requested_by
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 */
final class IdentityCheck extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'queued', 'attempts' => 0];

    protected $fillable = ['user_id', 'requested_by', 'reason'];

    protected function casts(): array
    {
        return ['result' => 'array', 'checked_at' => 'datetime', 'attempts' => 'integer', 'cost_cents' => 'integer'];
    }
}
