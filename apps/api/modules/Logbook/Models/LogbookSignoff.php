<?php

declare(strict_types=1);

namespace Modules\Logbook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $enrolment_id
 * @property string $user_id
 * @property string $module_id
 * @property string|null $mentor_id
 * @property string|null $signed_by
 * @property Carbon $signed_at
 * @property int $minutes
 * @property string $signature
 * @property string $statement_version
 * @property string|null $mentor_comment
 * @property string $status
 * @property string|null $checked_by
 * @property Carbon|null $checked_at
 * @property string|null $check_comment
 */
final class LogbookSignoff extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** What the mentor confirms at the end of a module. Bump the version if the words change. */
    public const STATEMENT = 'I confirm that the learner did the work in this logbook at our workplace, under my supervision, and that the entries are true.';

    public const STATEMENT_VERSION = 'v1';

    protected $attributes = ['status' => 'signed'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'checked_at' => 'datetime', 'minutes' => 'integer'];
    }
}
