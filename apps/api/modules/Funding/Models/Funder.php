<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string $kind
 * @property string|null $seta_code
 * @property string $template
 * @property string|null $contact_email
 */
final class Funder extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const KINDS = ['seta', 'employer', 'other'];

    public const TEMPLATES = ['generic', 'ceta'];

    protected $attributes = ['template' => 'generic'];

    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'kind' => $this->kind, 'seta_code' => $this->seta_code, 'template' => $this->template,
            'contact_email' => $this->contact_email];
    }
}
