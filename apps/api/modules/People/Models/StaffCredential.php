<?php

declare(strict_types=1);

namespace Modules\People\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A registration that lets someone facilitate, assess, moderate, advise on RPL or
 * mentor — with the body that registered them, its scope and its expiry.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role
 * @property string $body
 * @property string $registration_number
 * @property list<string> $qualification_ids
 * @property Carbon|null $starts_on
 * @property Carbon|null $expires_on
 * @property string|null $file_id
 * @property string $status pending | verified | rejected
 * @property string|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $notes
 * @property int|null $alerted_for
 * @property string|null $added_by
 */
final class StaffCredential extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const ROLES = [
        'facilitator' => 'Facilitator',
        'assessor' => 'Assessor',
        'moderator' => 'Moderator',
        'rpl_advisor' => 'RPL advisor',
        'mentor' => 'Workplace mentor',
        'subject_expert' => 'Subject matter expert',
    ];

    /** Common registering bodies, offered as suggestions (any body may be typed). */
    public const BODIES = ['QCTO', 'CETA', 'MERSETA', 'ETDP SETA', 'SERVICES SETA', 'EWSETA', 'MICT SETA', 'NAMB', 'SACE', 'ECSA'];

    protected $guarded = ['id', 'status', 'verified_by', 'verified_at'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['qualification_ids' => 'array', 'starts_on' => 'date', 'expires_on' => 'date', 'verified_at' => 'datetime', 'alerted_for' => 'integer'];
    }

    /** Verified and in its dates on the given day. */
    public function validOn(?CarbonImmutable $on = null): bool
    {
        $on ??= CarbonImmutable::today();

        return $this->status === 'verified'
            && ($this->starts_on === null || ! $on->lt($this->starts_on))
            && ($this->expires_on === null || ! $on->gt($this->expires_on));
    }

    public function covers(string $qualificationId): bool
    {
        return in_array($qualificationId, $this->qualification_ids, true);
    }

    /** ok | expiring (≤ 90 days) | expired | pending | rejected */
    public function state(): string
    {
        if ($this->status !== 'verified') {
            return $this->status;
        }
        if ($this->expires_on === null) {
            return 'ok';
        }
        $days = (int) CarbonImmutable::today()->diffInDays($this->expires_on, false);

        return $days < 0 ? 'expired' : ($days <= 90 ? 'expiring' : 'ok');
    }
}
