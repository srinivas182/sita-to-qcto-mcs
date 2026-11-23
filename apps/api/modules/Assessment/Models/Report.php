<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $cohort_id
 * @property string $module_id
 * @property string $moderator_id
 * @property string|null $credential_id
 * @property array<string, mixed> $summary
 * @property string $findings
 * @property string|null $recommendations
 * @property string $hash
 * @property Carbon $signed_at
 */
final class Report extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'moderation_reports';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'signed_at' => 'datetime'];
    }
}
