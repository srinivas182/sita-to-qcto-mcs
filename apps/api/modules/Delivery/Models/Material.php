<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $qualification_id
 * @property string|null $module_id
 * @property string $title
 * @property string|null $description
 * @property string $kind
 * @property string|null $file_id
 * @property string|null $url
 * @property string|null $low_file_id
 * @property string|null $low_status
 * @property int|null $duration_s
 * @property int $sort
 * @property string $status
 * @property int $version
 * @property string|null $created_by
 * @property Carbon|null $updated_at
 */
final class Material extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const KINDS = ['document' => 'Document', 'video' => 'Video', 'image' => 'Picture', 'link' => 'Link'];

    protected $attributes = ['status' => 'draft', 'version' => 1, 'sort' => 0];

    protected $fillable = ['qualification_id', 'module_id', 'title', 'description', 'kind', 'file_id', 'url', 'sort', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'sort' => 'integer', 'duration_s' => 'integer'];
    }
}
