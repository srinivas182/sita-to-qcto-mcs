<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Tenancy\Context\TenantContext;

/**
 * Loads the signed-in person for a session regardless of tenant context. People
 * are global; *whether they may act here* is decided afterwards by the
 * EnsureAccess middleware (membership, platform role or support grant).
 */
final class PlatformUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return app(TenantContext::class)->runAsPlatform(fn () => parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return app(TenantContext::class)->runAsPlatform(fn () => parent::retrieveByToken($identifier, $token));
    }

    /** @param  string  $token */
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        app(TenantContext::class)->runAsPlatform(fn () => parent::updateRememberToken($user, $token));
    }

    /** @param  array<string, mixed>  $credentials */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        return app(TenantContext::class)->runAsPlatform(fn () => parent::retrieveByCredentials($credentials));
    }
}
