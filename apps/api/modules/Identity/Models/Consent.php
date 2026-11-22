<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * POPIA consent record: which notice version a person accepted, when, and when they
 * withdrew. Records are never deleted; a new version is a new row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $purpose
 * @property string $version
 * @property Carbon $granted_at
 * @property Carbon|null $withdrawn_at
 * @property string|null $ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Consent extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'user_id', 'purpose', 'version', 'granted_at', 'withdrawn_at', 'ip'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
