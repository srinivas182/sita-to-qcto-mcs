<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property string $tranche_id
 * @property string $number
 * @property string $status
 * @property list<array{enrolment_id: string, user_id: string, name: string|null, amount_cents: int, disability_cents: int}> $learners
 * @property int $amount_cents
 * @property Carbon|null $sent_on
 * @property Carbon|null $paid_on
 * @property int|null $paid_cents
 * @property string|null $proof_file_id
 * @property string|null $closing_note
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
final class GrantClaim extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Claims that still hold their learners for the tranche. */
    public const LIVE = ['draft', 'sent', 'queried', 'paid'];

    protected $attributes = ['status' => 'draft'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['learners' => 'array', 'sent_on' => 'date', 'paid_on' => 'date', 'amount_cents' => 'integer', 'paid_cents' => 'integer'];
    }
}
