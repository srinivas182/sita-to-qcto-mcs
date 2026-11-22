<?php

declare(strict_types=1);

namespace Modules\Employers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A host employer: where learners do their work experience.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_name
 * @property string|null $trading_name
 * @property string|null $registration_number
 * @property string|null $sdl_number
 * @property string|null $seta
 * @property string|null $bbbee_level
 * @property string|null $sector
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $status
 * @property string|null $notes
 */
final class Employer extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active'];

    /** @return HasMany<EmployerSite, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(EmployerSite::class)->orderBy('name');
    }

    /** @return HasMany<Mentor, $this> */
    public function mentors(): HasMany
    {
        return $this->hasMany(Mentor::class)->orderBy('name');
    }
}
