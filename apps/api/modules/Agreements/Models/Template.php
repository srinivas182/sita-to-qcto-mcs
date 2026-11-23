<?php

declare(strict_types=1);

namespace Modules\Agreements\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * One version of a provider's agreement wording.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $family_id
 * @property int $version
 * @property string $kind
 * @property string $name
 * @property string $body
 * @property list<string> $parties
 * @property bool $advanced_signature
 * @property string $status
 * @property Carbon|null $published_at
 * @property string|null $published_by
 * @property Carbon $updated_at
 */
final class Template extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const KINDS = ['workplace', 'employer_mou', 'learner'];

    public const ROLES = ['learner', 'employer', 'provider'];

    protected $table = 'agreement_templates';

    protected $guarded = ['id', 'status', 'published_at', 'published_by'];

    protected function casts(): array
    {
        return ['parties' => 'array', 'advanced_signature' => 'boolean', 'version' => 'integer', 'published_at' => 'datetime'];
    }
}
