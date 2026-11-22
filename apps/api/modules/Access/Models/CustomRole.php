<?php

declare(strict_types=1);

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * A role a provider built itself. Its key (`custom:<ulid>`) is what role assignments store.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $scope tenant | centre
 * @property list<string> $permissions
 * @property bool $mfa
 * @property string|null $based_on
 * @property string|null $created_by
 */
final class CustomRole extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const PREFIX = 'custom:';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'mfa' => 'boolean'];
    }

    public static function isCustom(string $role): bool
    {
        return str_starts_with($role, self::PREFIX);
    }
}
