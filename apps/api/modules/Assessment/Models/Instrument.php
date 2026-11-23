<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $module_id
 * @property string $method
 * @property string $title
 * @property list<array{key: string, text: string}> $criteria
 * @property int|null $pass_mark
 * @property string|null $instructions
 * @property bool $active
 * @property int $sort
 * @property string|null $task_id
 */
final class Instrument extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const METHODS = ['test', 'observation', 'project', 'assignment', 'logbook'];

    protected $table = 'assessment_instruments';

    protected $attributes = ['active' => true, 'sort' => 0];

    protected $fillable = ['module_id', 'method', 'title', 'criteria', 'pass_mark', 'instructions', 'active', 'sort', 'task_id'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'active' => 'boolean', 'pass_mark' => 'integer', 'sort' => 'integer'];
    }
}
