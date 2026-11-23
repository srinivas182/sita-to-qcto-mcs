<?php

declare(strict_types=1);

namespace Modules\Agreements\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property bool $advanced_by_default
 * @property int $valid_days
 */
final class AgreementSettings extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'agreement_settings';

    protected $fillable = ['advanced_by_default', 'valid_days'];

    protected function casts(): array
    {
        return ['advanced_by_default' => 'boolean', 'valid_days' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['advanced_by_default' => false, 'valid_days' => 30]);
    }
}
