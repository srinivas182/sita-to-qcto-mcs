<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * Short-lived mobile access token, bound to one tenant (RLS) and one device.
 *
 * @property string $tenant_id
 * @property string|null $device_id
 */
class PersonalAccessToken extends SanctumToken
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'device_id', 'name', 'token', 'abilities', 'expires_at'];

    /**
     * Sanctum stamps last_used_at on every request. Writing it at most every five
     * minutes keeps authenticated reads free of a database write.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $previous = $this->getOriginal('last_used_at');

        if ($this->exists && array_keys($this->getDirty()) === ['last_used_at']
            && $previous instanceof \DateTimeInterface && $previous->getTimestamp() > time() - 300) {
            return true;
        }

        return parent::save($options);
    }
}
