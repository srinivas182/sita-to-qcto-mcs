<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $cohort_id
 * @property string $stage
 * @property list<string> $learner_ids
 * @property int $rows
 * @property Carbon $sent_on
 * @property string|null $note
 * @property string|null $submitted_by
 */
final class QctoSubmission extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['cohort_id', 'stage', 'learner_ids', 'rows', 'sent_on', 'note', 'submitted_by'];

    protected function casts(): array
    {
        return ['learner_ids' => 'array', 'sent_on' => 'date', 'rows' => 'integer'];
    }
}
