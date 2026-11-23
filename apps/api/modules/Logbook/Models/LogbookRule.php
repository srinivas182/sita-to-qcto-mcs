<?php

declare(strict_types=1);

namespace Modules\Logbook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $module_id
 * @property list<array{key: string, title: string, photo: bool}> $activities
 * @property int $hours_needed
 * @property string|null $evidence_note
 */
final class LogbookRule extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['module_id', 'activities', 'hours_needed', 'evidence_note'];

    protected function casts(): array
    {
        return ['activities' => 'array', 'hours_needed' => 'integer'];
    }
}
