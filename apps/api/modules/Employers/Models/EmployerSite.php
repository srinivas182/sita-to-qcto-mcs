<?php

declare(strict_types=1);

namespace Modules\Employers\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A workplace where learners are placed, with its SETA workplace approval and the
 * number of learners each mentor may guide (1:10 unless set otherwise).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employer_id
 * @property string $name
 * @property string|null $address_line1
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $approval_number
 * @property string|null $approval_body
 * @property Carbon|null $approved_on
 * @property Carbon|null $approval_expires_on
 * @property list<string>|null $approved_qualification_ids
 * @property string|null $approval_file_id
 * @property int $mentor_ratio
 * @property array<string, int>|null $ratio_overrides
 * @property string $status
 * @property int|null $alerted_for
 */
final class EmployerSite extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const DEFAULT_RATIO = 10;

    protected $guarded = ['id', 'alerted_for'];

    protected $attributes = ['status' => 'active', 'mentor_ratio' => self::DEFAULT_RATIO];

    protected function casts(): array
    {
        return [
            'approved_on' => 'date', 'approval_expires_on' => 'date', 'approved_qualification_ids' => 'array',
            'ratio_overrides' => 'array', 'mentor_ratio' => 'integer', 'alerted_for' => 'integer',
        ];
    }

    /** approved | expiring (≤ 90 days) | expired | not_approved */
    public function approvalState(?CarbonImmutable $on = null): string
    {
        $on ??= CarbonImmutable::today();
        if ($this->approval_number === null) {
            return 'not_approved';
        }
        if ($this->approval_expires_on === null) {
            return 'approved';
        }
        $days = (int) $on->diffInDays($this->approval_expires_on, false);

        return $days < 0 ? 'expired' : ($days <= 90 ? 'expiring' : 'approved');
    }

    /** Approved, in date, and approved for this qualification. */
    public function approvedFor(string $qualificationId, ?CarbonImmutable $on = null): bool
    {
        return in_array($this->approvalState($on), ['approved', 'expiring'], true)
            && in_array($qualificationId, $this->approved_qualification_ids ?? [], true);
    }

    public function ratioFor(string $qualificationId): int
    {
        return (int) ($this->ratio_overrides[$qualificationId] ?? $this->mentor_ratio);
    }
}
