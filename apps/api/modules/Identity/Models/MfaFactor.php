<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;

/**
 * Authenticator-app (TOTP) factor. The secret is encrypted; recovery codes are kept
 * only as keyed hashes (shown once, never retrievable).
 *
 * @property string $id
 * @property string $user_id
 * @property string $type
 * @property string $secret
 * @property list<string>|null $recovery_codes
 * @property int|null $last_timestep
 * @property Carbon|null $confirmed_at
 */
class MfaFactor extends Model
{
    use Auditable;
    use HasUlids;

    protected $fillable = ['user_id', 'type', 'secret', 'recovery_codes', 'last_timestep', 'confirmed_at'];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'array',
            'last_timestep' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
