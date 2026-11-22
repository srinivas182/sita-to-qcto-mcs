<?php

declare(strict_types=1);

namespace Modules\Qualifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A provider's suggestion for the catalogue: a qualification to add, or a correction.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $qualification_id
 * @property string $kind new | correction
 * @property string|null $saqa_id
 * @property string $title
 * @property string $details
 * @property string|null $file_id
 * @property string $status open | accepted | declined
 * @property string|null $response
 * @property string $submitted_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
final class Suggestion extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'catalogue_suggestions';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
