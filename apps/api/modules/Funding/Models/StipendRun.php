<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $agreement_id
 * @property Carbon $month
 * @property string|null $register_id
 * @property string $status
 * @property int $total_cents
 * @property string|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $paid_on
 * @property string|null $proof_file_id
 * @property string|null $created_by
 */
final class StipendRun extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'draft', 'total_cents' => 0];

    protected $guarded = ['id', 'tenant_id', 'status', 'confirmed_by', 'confirmed_at', 'paid_on', 'proof_file_id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'confirmed_at' => 'datetime', 'paid_on' => 'date', 'total_cents' => 'integer'];
    }
}
