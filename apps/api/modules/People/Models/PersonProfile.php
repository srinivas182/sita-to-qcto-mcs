<?php

declare(strict_types=1);

namespace Modules\People\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * What a provider keeps about a person. Equity data (gender, race, disability) is
 * optional, only with explicit consent, and encrypted at rest.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string|null $home_language
 * @property string|null $emergency_name
 * @property string|null $emergency_phone
 * @property string|null $emergency_relationship
 * @property Carbon|null $equity_consented_at
 * @property string|null $equity_consent_version
 * @property string|null $title
 * @property string|null $middle_name
 * @property string|null $nationality
 * @property string|null $citizen_status
 * @property string|null $socioeconomic_status
 * @property bool $postal_same
 * @property string|null $postal_line1
 * @property string|null $postal_line2
 * @property string|null $postal_code_postal
 * @property string|null $statssa_area_code
 * @property array{gender?: string|null, race?: string|null, disability?: bool|null, disability_details?: string|null, disability_code?: string|null, disability_rating?: string|null}|null $equity
 */
final class PersonProfile extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const EQUITY_CONSENT_VERSION = '2026-12';

    protected $guarded = ['id'];

    protected $hidden = ['equity'];

    protected function casts(): array
    {
        return ['equity' => 'encrypted:array', 'equity_consented_at' => 'datetime', 'postal_same' => 'boolean'];
    }
}
