<?php

declare(strict_types=1);

namespace Modules\Employers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A workplace mentor (the mentor matrix SETAs ask for: designation, years of
 * experience, highest qualification) and the sites where they guide learners.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employer_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $designation
 * @property int $years_experience
 * @property string|null $highest_qualification
 * @property int|null $qualification_nqf_level
 * @property list<string> $site_ids
 * @property bool $active
 */
final class Mentor extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'employer_mentors';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['site_ids' => 'array', 'active' => 'boolean', 'years_experience' => 'integer', 'qualification_nqf_level' => 'integer'];
    }
}
