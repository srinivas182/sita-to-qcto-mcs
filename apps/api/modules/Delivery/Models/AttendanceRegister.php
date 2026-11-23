<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $cohort_id
 * @property Carbon $month
 * @property string $status
 * @property array{sessions: list<array<string, mixed>>, learners: list<array<string, mixed>>} $grid
 * @property string $grid_sha256
 * @property string|null $file_id
 * @property Carbon|null $submitted_on
 * @property string|null $submitted_note
 * @property string|null $created_by
 * @property Carbon|null $reminded_at
 */
final class AttendanceRegister extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'signing'];

    protected $fillable = ['cohort_id', 'month', 'grid', 'grid_sha256', 'created_by'];

    protected function casts(): array
    {
        return ['month' => 'date', 'grid' => 'array', 'submitted_on' => 'date', 'reminded_at' => 'datetime'];
    }
}
