<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A phone registered for sign-in. Its Ed25519 public key proves possession on token
 * refresh; revoking the device ends its sessions and tells the app to wipe local data.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $platform
 * @property string $name
 * @property string|null $push_token
 * @property string $public_key
 * @property string $offline_key_fingerprint
 * @property Carbon|null $offline_until
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Device extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'user_id', 'platform', 'name', 'push_token', 'public_key',
        'offline_key_fingerprint', 'offline_until', 'last_seen_at', 'revoked_at',
    ];

    protected $hidden = ['push_token'];

    protected function casts(): array
    {
        return ['offline_until' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
