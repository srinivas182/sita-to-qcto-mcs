<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $intake_id
 * @property string $user_id
 * @property string $reference
 * @property string $status
 * @property array<string, string|bool>|null $answers
 * @property string|null $reason
 * @property int|null $waitlist_position
 * @property string $source
 * @property Carbon $submitted_at
 * @property Carbon|null $decided_at
 * @property string|null $decided_by
 * @property Carbon|null $documents_requested_at
 */
final class Application extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Statuses that still hold or may still get a place. */
    public const ACTIVE = ['submitted', 'shortlisted', 'waitlisted', 'offered', 'accepted', 'enrolled'];

    /** Defaults, so a new application reads them before it is re-loaded. */
    protected $attributes = ['status' => 'submitted', 'source' => 'web'];

    protected $fillable = ['intake_id', 'user_id', 'reference', 'answers', 'source', 'submitted_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'waitlist_position' => 'integer', 'documents_requested_at' => 'datetime'];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }
}
