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
 * @property int $number
 * @property string $share_pct
 * @property list<array{key: string, months?: int}> $milestones
 * @property list<array{title: string, file_id: string|null}> $evidence
 * @property Carbon|null $due_on
 */
final class GrantTranche extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['milestones' => 'array', 'evidence' => 'array', 'due_on' => 'date', 'number' => 'integer'];
    }
}
