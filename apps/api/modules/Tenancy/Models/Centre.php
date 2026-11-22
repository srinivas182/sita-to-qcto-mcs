<?php

declare(strict_types=1);

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Concerns\BelongsToTenant;
use Modules\Tenancy\Database\Factories\CentreFactory;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $accreditation_number
 * @property Carbon|null $accreditation_expires_on
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Centre extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CentreFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['tenant_id', 'name', 'accreditation_number', 'accreditation_expires_on', 'status'];

    protected function casts(): array
    {
        return ['accreditation_expires_on' => 'date'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): CentreFactory
    {
        return CentreFactory::new();
    }
}
