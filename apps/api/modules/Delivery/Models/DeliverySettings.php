<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property int $radius_m
 * @property int $late_after_minutes
 * @property int $threshold_pct
 * @property int $offline_sync_hours
 */
final class DeliverySettings extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'delivery_settings';

    protected $attributes = ['radius_m' => 200, 'late_after_minutes' => 10, 'threshold_pct' => 80, 'offline_sync_hours' => 72];

    protected $fillable = ['radius_m', 'late_after_minutes', 'threshold_pct', 'offline_sync_hours'];

    protected function casts(): array
    {
        return ['radius_m' => 'integer', 'late_after_minutes' => 'integer', 'threshold_pct' => 'integer', 'offline_sync_hours' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
