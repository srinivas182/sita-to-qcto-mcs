<?php

declare(strict_types=1);

namespace Modules\Access\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Access\Services\AccessService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /v1/admin routes: platform host only, a platform permission is required,
 * and the rest of the request runs in the platform (cross-tenant) context. Entries
 * written to the audit log are flagged as platform actions.
 *
 * Usage: ->middleware('platform:platform.tenants.manage')
 */
final class EnsurePlatformPermission
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessService $access,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in first.']], 401);
        }

        if ($this->context->hasTenant() || ! $this->access->can($user, $permission)) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'You do not have access to this.']], 403);
        }

        $this->context->setPlatform();

        return $next($request);
    }
}
