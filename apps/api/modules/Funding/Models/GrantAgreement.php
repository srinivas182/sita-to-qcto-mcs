<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $funder_id
 * @property string $cohort_id
 * @property string $reference
 * @property int $learners
 * @property int $amount_per_learner_cents
 * @property string $funding_kind
 * @property int|null $disability_allowance_cents
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $moa_file_id
 * @property string $status
 * @property string|null $created_by
 * @property int|null $stipend_cents
 * @property int $stipend_full_pct
 * @property int $stipend_hold_pct
 * @property bool $stipend_prorata
 */
final class GrantAgreement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'active', 'funding_kind' => 'full', 'stipend_full_pct' => 80, 'stipend_hold_pct' => 50, 'stipend_prorata' => true];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'learners' => 'integer', 'amount_per_learner_cents' => 'integer', 'disability_allowance_cents' => 'integer',
            'stipend_cents' => 'integer', 'stipend_full_pct' => 'integer', 'stipend_hold_pct' => 'integer', 'stipend_prorata' => 'boolean'];
    }
}
