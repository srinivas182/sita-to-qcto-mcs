<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $register_id
 * @property string $user_id
 * @property string $role
 * @property string $status
 * @property string|null $signed_name
 * @property string|null $dispute
 * @property Carbon|null $signed_at
 * @property string|null $ip
 * @property string|null $channel
 */
final class RegisterSignature extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['register_id', 'user_id', 'role'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
