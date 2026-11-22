<?php

declare(strict_types=1);

namespace Modules\Employers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * Someone who acts for an employer (host-employer role). Screens for employers show
 * only the learners placed with the employers a person belongs to.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employer_id
 * @property string $user_id
 */
final class EmployerMember extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];
}
