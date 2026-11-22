<?php

declare(strict_types=1);

namespace Modules\Onboarding\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A training provider's application to join. Platform data until approved.
 *
 * @property string $id
 * @property string $reference
 * @property string $status
 * @property string $token_hash
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $email_code_hash
 * @property Carbon|null $email_code_expires_at
 * @property int $email_code_attempts
 * @property Carbon|null $email_verified_at
 * @property string $legal_name
 * @property string|null $trading_name
 * @property string|null $registration_number
 * @property string|null $vat_number
 * @property string|null $website
 * @property string|null $sdp_number
 * @property Carbon|null $accreditation_expires_on
 * @property list<string>|null $scope
 * @property string|null $centre_name
 * @property string|null $address_line1
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string|null $preferred_code
 * @property string|null $package_id
 * @property string $interval
 * @property Carbon|null $consented_at
 * @property string|null $consent_version
 * @property array<string, array{ok: bool, message: string}>|null $auto_checks
 * @property array<string, bool>|null $checklist
 * @property string|null $review_notes
 * @property string|null $decision_reason
 * @property string|null $reviewed_by
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property string|null $tenant_id
 * @property string|null $ip
 * @property Carbon $created_at
 * @property-read Collection<int, ApplicationFile> $files
 */
final class Application extends Model
{
    use HasUlids;

    protected $table = 'tenant_applications';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'email_code_hash'];

    protected function casts(): array
    {
        return [
            'email_code_expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'accreditation_expires_on' => 'date',
            'scope' => 'array',
            'consented_at' => 'datetime',
            'auto_checks' => 'array',
            'checklist' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'email_code_attempts' => 'integer',
        ];
    }

    /** @return HasMany<ApplicationFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ApplicationFile::class);
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'info_requested'], true);
    }
}
