<?php

declare(strict_types=1);

namespace Modules\Qualifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A qualification a provider offers (adopted from the catalogue, never copied).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $qualification_id
 * @property bool $active
 * @property list<string>|null $modes
 * @property int|null $price_cents
 * @property string|null $notes
 * @property int|null $alerted_for
 */
final class Programme extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const MODES = ['contact', 'blended', 'online', 'workplace'];

    protected $table = 'tenant_qualifications';

    protected $fillable = ['tenant_id', 'qualification_id', 'active', 'modes', 'price_cents', 'notes', 'alerted_for'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'modes' => 'array', 'price_cents' => 'integer', 'alerted_for' => 'integer'];
    }

    /** @return BelongsTo<Qualification, $this> */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }
}
