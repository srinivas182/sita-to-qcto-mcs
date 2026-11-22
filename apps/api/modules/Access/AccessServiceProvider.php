<?php

declare(strict_types=1);

namespace Modules\Access;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Http\Middleware\EnsurePlatformPermission;
use Modules\Access\Services\AccessService;
use Modules\Identity\Models\User;
use Modules\Support\ModuleServiceProvider;

final class AccessServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Catalogue::class);
        $this->app->scoped(AccessService::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('platform', EnsurePlatformPermission::class);

        // Catalogue permissions are answered by AccessService; anything else falls
        // through to normal policies. Gate::authorize('learners.view', $centreId).
        Gate::before(function (mixed $user, string $ability, array $arguments): ?bool {
            if (! $user instanceof User || ! $this->app->make(Catalogue::class)->hasPermission($ability)) {
                return null;
            }

            $centre = $arguments[0] ?? null;

            return $this->app->make(AccessService::class)->can($user, $ability, is_string($centre) ? $centre : null);
        });
    }
}
