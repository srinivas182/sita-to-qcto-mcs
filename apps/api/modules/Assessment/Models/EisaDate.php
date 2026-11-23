<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $qualification_id
 * @property string|null $replaces_id
 * @property Carbon|null $eisa_on
 * @property Carbon|null $closes_on
 * @property string $source
 */
final class EisaDate extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'eisa_dates';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['eisa_on' => 'date', 'closes_on' => 'date'];
    }
}
