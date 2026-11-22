<?php

declare(strict_types=1);

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $email
 * @property string|null $phone_e164
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $role
 * @property string|null $centre_id
 * @property string $token_hash
 * @property string|null $id_type
 * @property string|null $id_number encrypted; applied to the person on acceptance
 * @property string $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invitation extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'email', 'phone_e164', 'first_name', 'last_name', 'role', 'centre_id',
        'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_by', 'revoked_at', 'id_type', 'id_number',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime', 'id_number' => 'encrypted'];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
