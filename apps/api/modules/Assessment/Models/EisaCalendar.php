<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $qualification_id
 * @property string|null $quality_partner
 * @property Carbon $eisa_on
 * @property Carbon $closes_on
 * @property string|null $source
 */
final class EisaCalendar extends Model
{
    use HasUlids;

    protected $table = 'eisa_calendar';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['eisa_on' => 'date', 'closes_on' => 'date'];
    }
}
