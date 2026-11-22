<?php

declare(strict_types=1);

namespace Modules\Qualifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A qualification inside a centre's accreditation scope.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $centre_id
 * @property string $qualification_id
 * @property string|null $accreditation_number
 * @property Carbon|null $expires_on
 */
final class CentreQualification extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'centre_id', 'qualification_id', 'accreditation_number', 'expires_on'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }
}
