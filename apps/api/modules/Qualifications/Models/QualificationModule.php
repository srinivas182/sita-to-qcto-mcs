<?php

declare(strict_types=1);

namespace Modules\Qualifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A knowledge (KM), practical skill (PM) or work experience (WM) module of an
 * occupational qualification, or a unit standard (US) of a legacy one.
 *
 * @property string $id
 * @property string $qualification_id
 * @property string $kind KM | PM | WM | US
 * @property string $group compulsory | elective | fundamental | core
 * @property string $code
 * @property string $title
 * @property int|null $nqf_level
 * @property int $credits
 * @property int $sort
 * @property string|null $workplace_criteria
 */
final class QualificationModule extends Model
{
    use HasUlids;

    public const KINDS = ['KM' => 'Knowledge module', 'PM' => 'Practical skill module', 'WM' => 'Work experience module', 'US' => 'Unit standard'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['credits' => 'integer', 'nqf_level' => 'integer', 'sort' => 'integer'];
    }

    /** @return BelongsTo<Qualification, $this> */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'kind_label' => self::KINDS[$this->kind] ?? $this->kind, 'group' => $this->group,
            'code' => $this->code, 'title' => $this->title, 'nqf_level' => $this->nqf_level, 'credits' => $this->credits,
            'workplace_criteria' => $this->workplace_criteria,
        ];
    }
}
