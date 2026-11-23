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
 * @property string $file_id
 * @property string $type
 * @property bool $certified
 * @property Carbon|null $certified_on
 * @property string|null $certified_by
 * @property array<string, mixed>|null $checks
 * @property string $status
 * @property string|null $reason
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 */
final class LearnerDocument extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'pending', 'certified' => false];

    protected $fillable = ['user_id', 'file_id', 'type', 'certified', 'certified_on', 'certified_by', 'checks'];

    protected function casts(): array
    {
        return ['certified' => 'boolean', 'certified_on' => 'date', 'checks' => 'array', 'reviewed_at' => 'datetime'];
    }
}
