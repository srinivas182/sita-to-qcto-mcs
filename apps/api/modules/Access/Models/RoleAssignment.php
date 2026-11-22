<?php

declare(strict_types=1);

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Identity\Models\User;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A role held within a training provider; centre_id null means "all centres".
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role
 * @property string|null $centre_id
 * @property string|null $granted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoleAssignment extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'centre_id', 'granted_by'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
