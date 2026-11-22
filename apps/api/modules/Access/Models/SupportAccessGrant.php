<?php

declare(strict_types=1);

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * Consent-based support access: platform staff may enter a provider's space only
 * inside an approved, time-boxed window. Every action is audited with the grant id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $granted_to
 * @property string $reason
 * @property int $minutes
 * @property string $status pending|approved|denied|revoked
 * @property string|null $approved_by
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SupportAccessGrant extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'granted_to', 'reason', 'minutes', 'status', 'approved_by', 'starts_at', 'ends_at', 'decided_at'];

    protected function casts(): array
    {
        return ['minutes' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === 'approved'
            && $this->starts_at !== null && $this->starts_at->isPast()
            && $this->ends_at !== null && $this->ends_at->isFuture();
    }
}
