<?php

declare(strict_types=1);

namespace Modules\Qualifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A qualification in the shared catalogue (platform-owned; providers read it once
 * published). Occupational types are built from KM/PM/WM modules; legacy ones from
 * unit standards in fundamental, core and elective groups.
 *
 * @property string $id
 * @property string $type
 * @property string|null $saqa_id
 * @property string|null $curriculum_code
 * @property string $title
 * @property int $nqf_level
 * @property int $credits
 * @property string|null $quality_partner
 * @property string|null $assessment_partner
 * @property bool $eisa
 * @property string $status draft | published | withdrawn
 * @property Carbon|null $registration_end
 * @property Carbon|null $last_enrolment_on
 * @property Carbon|null $last_achievement_on
 * @property list<string>|null $successor_ids
 * @property array<string, int>|null $credit_rules
 * @property string|null $source_url
 * @property string|null $notes
 * @property Carbon|null $published_at
 * @property string|null $published_by
 * @property int $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Qualification extends Model
{
    use HasUlids;

    public const TYPES = [
        'occupational_certificate' => 'Occupational certificate',
        'part_qualification' => 'Occupational part-qualification',
        'skills_programme' => 'Skills programme',
        'trade' => 'Trade (occupational certificate with trade test)',
        'legacy_qualification' => 'Legacy qualification (pre-2009, unit standards)',
        'legacy_skills_programme' => 'Legacy skills programme (unit standards)',
    ];

    /** Days before the last enrolment date at which a qualification counts as closing. */
    public const CLOSING_DAYS = 180;

    protected $guarded = ['id', 'status', 'published_at', 'published_by', 'version'];

    protected $attributes = ['status' => 'draft', 'eisa' => false, 'version' => 1];

    protected function casts(): array
    {
        return [
            'eisa' => 'boolean', 'nqf_level' => 'integer', 'credits' => 'integer', 'version' => 'integer',
            'registration_end' => 'date', 'last_enrolment_on' => 'date', 'last_achievement_on' => 'date',
            'successor_ids' => 'array', 'credit_rules' => 'array', 'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<QualificationModule, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(QualificationModule::class)->orderBy('sort')->orderBy('code');
    }

    public function isLegacy(): bool
    {
        return str_starts_with($this->type, 'legacy_');
    }

    /**
     * open | closing (last enrolment within 180 days) | closed (no new enrolments,
     * enrolled learners may still finish) | ended (past last achievement) | withdrawn | draft
     */
    public function enrolmentState(?CarbonImmutable $on = null): string
    {
        $on ??= CarbonImmutable::today();
        if ($this->status !== 'published') {
            return $this->status;
        }
        if ($this->last_achievement_on !== null && $on->gt($this->last_achievement_on)) {
            return 'ended';
        }
        if ($this->last_enrolment_on === null) {
            return 'open';
        }
        if ($on->gt($this->last_enrolment_on)) {
            return 'closed';
        }

        return $on->diffInDays($this->last_enrolment_on) <= self::CLOSING_DAYS ? 'closing' : 'open';
    }

    public function enrolmentOpen(?CarbonImmutable $on = null): bool
    {
        return in_array($this->enrolmentState($on), ['open', 'closing'], true);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $days = $this->last_enrolment_on !== null ? (int) CarbonImmutable::today()->diffInDays($this->last_enrolment_on, false) : null;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => self::TYPES[$this->type] ?? $this->type,
            'legacy' => $this->isLegacy(),
            'saqa_id' => $this->saqa_id,
            'curriculum_code' => $this->curriculum_code,
            'title' => $this->title,
            'nqf_level' => $this->nqf_level,
            'credits' => $this->credits,
            'quality_partner' => $this->quality_partner,
            'assessment_partner' => $this->assessment_partner,
            'eisa' => $this->eisa,
            'status' => $this->status,
            'registration_end' => $this->registration_end?->toDateString(),
            'last_enrolment_on' => $this->last_enrolment_on?->toDateString(),
            'last_achievement_on' => $this->last_achievement_on?->toDateString(),
            'days_to_last_enrolment' => $days,
            'enrolment' => $this->enrolmentState(),
            'successor_ids' => $this->successor_ids ?? [],
            'version' => $this->version,
        ];
    }
}
