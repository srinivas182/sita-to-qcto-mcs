<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $cohort_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property Carbon $opens_on
 * @property Carbon $closes_on
 * @property int $places
 * @property int|null $min_age
 * @property int|null $max_age
 * @property list<string>|null $document_types
 * @property list<array{key: string, label: string, type: string, options?: list<string>, required?: bool}>|null $questions
 * @property string $status
 * @property string|null $created_by
 */
final class Intake extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id', 'slug', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['opens_on' => 'date', 'closes_on' => 'date', 'places' => 'integer', 'min_age' => 'integer', 'max_age' => 'integer',
            'document_types' => 'array', 'questions' => 'array'];
    }

    /** Open, and today within its dates. */
    public function accepting(): bool
    {
        return $this->status === 'open' && ! now()->startOfDay()->lt($this->opens_on) && ! now()->startOfDay()->gt($this->closes_on);
    }
}
