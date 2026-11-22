<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $driver
 * @property string $event_id
 * @property string $type
 * @property string|null $reference
 * @property string $payload encrypted
 * @property string|null $result
 * @property Carbon|null $processed_at
 */
final class GatewayEvent extends Model
{
    use HasUlids;

    protected $fillable = ['driver', 'event_id', 'type', 'reference', 'payload', 'result', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted', 'processed_at' => 'datetime'];
    }
}
