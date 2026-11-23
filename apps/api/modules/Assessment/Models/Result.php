<?php

declare(strict_types=1);

namespace Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $user_id
 * @property string $module_id
 * @property string $instrument_id
 * @property int $attempt
 * @property string $assessor_id
 * @property string|null $credential_id
 * @property string $outcome
 * @property int|null $score
 * @property list<array{key: string, met: bool, comment: string|null}> $criteria
 * @property list<string> $file_ids
 * @property string|null $feedback
 * @property string|null $remediation
 * @property Carbon $assessed_on
 * @property Carbon $decided_at
 * @property string|null $client_id
 * @property string $hash
 * @property string $status
 * @property string|null $replaces_id
 */
final class Result extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Results that stand (not replaced by a moderation overturn or an appeal). */
    public const STANDING = ['decided', 'final'];

    protected $table = 'assessment_results';

    protected $attributes = ['status' => 'decided', 'file_ids' => '[]'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'file_ids' => 'array', 'assessed_on' => 'date', 'decided_at' => 'datetime', 'attempt' => 'integer', 'score' => 'integer'];
    }
}
