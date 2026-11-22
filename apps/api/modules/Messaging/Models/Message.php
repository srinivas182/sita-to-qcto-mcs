<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * One delivery attempt. Message text is never stored (it may hold a sign-in code);
 * the number is kept only as a masked label and a keyed hash.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $channel
 * @property string $driver
 * @property string $purpose
 * @property string $destination_hash
 * @property string $destination_masked
 * @property string $status
 * @property string|null $provider_message_id
 * @property int|null $cost_cents
 * @property string|null $error
 * @property string|null $fallback_of
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 */
final class Message extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'channel', 'driver', 'purpose', 'destination_hash', 'destination_masked', 'status',
        'provider_message_id', 'cost_cents', 'error', 'fallback_of', 'sent_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return ['cost_cents' => 'integer', 'sent_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'channel' => $this->channel,
            'driver' => $this->driver,
            'purpose' => $this->purpose,
            'to' => $this->destination_masked,
            'status' => $this->status,
            'cost_cents' => $this->cost_cents,
            'error' => $this->error,
            'fallback' => $this->fallback_of !== null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
