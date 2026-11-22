<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Identity\Support\MembershipStatus;
use Modules\Tenancy\Concerns\BelongsToTenant;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;

/**
 * A person's membership of a training provider.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string|null $centre_id
 * @property MembershipStatus $status
 * @property string|null $invited_by
 * @property Carbon|null $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserTenant extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'user_tenants';

    protected $fillable = ['tenant_id', 'user_id', 'centre_id', 'status', 'invited_by', 'joined_at'];

    protected function casts(): array
    {
        return ['status' => MembershipStatus::class, 'joined_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Centre, $this> */
    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }
}
