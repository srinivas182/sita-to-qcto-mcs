<?php

declare(strict_types=1);

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Concerns\Auditable;

/**
 * A platform-wide role (Super Admin, regulators, ecosystem). Readable only in the
 * platform context.
 *
 * @property string $id
 * @property string $user_id
 * @property string $role
 * @property string|null $granted_by
 */
class PlatformRoleAssignment extends Model
{
    use Auditable;
    use HasUlids;

    protected $fillable = ['user_id', 'role', 'granted_by'];
}
