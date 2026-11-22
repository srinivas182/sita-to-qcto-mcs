<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;

/**
 * A WebAuthn credential. `source` is the serialised credential record (public key,
 * counter, transports); no secret is stored.
 *
 * @property string $id
 * @property string $user_id
 * @property string $credential_id
 * @property array<string, mixed> $source
 * @property int $sign_count
 * @property string $name
 * @property string|null $aaguid
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Passkey extends Model
{
    use Auditable;
    use HasUlids;

    protected $fillable = ['user_id', 'credential_id', 'source', 'sign_count', 'name', 'aaguid', 'last_used_at'];

    /** @var list<string> */
    protected array $auditRedact = ['source'];

    protected function casts(): array
    {
        return ['source' => 'array', 'sign_count' => 'integer', 'last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
