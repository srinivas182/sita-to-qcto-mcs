<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Access\Models\SupportAccessGrant;
use Modules\Access\Services\AccessService;
use Modules\Audit\AuditLogger;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * After authentication: may this person act in the current place?
 *
 * - Tenant context: an active member, or platform staff inside an approved support
 *   window (their actions are then tagged with the grant in the audit log).
 * - Platform host: platform staff only.
 */
final class EnsureAccess
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->isActive()) {
            throw AuthError::accountDisabled();
        }

        if (! $this->context->hasTenant()) {
            if (! $this->access->isPlatformStaff($user)) {
                throw AuthError::notHere();
            }

            return $next($request);
        }

        if ($this->access->isMember($user)) {
            return $next($request);
        }

        $grant = $this->access->isPlatformStaff($user)
            ? SupportAccessGrant::query()->where('granted_to', $user->id)->where('status', 'approved')
                ->where('starts_at', '<=', now())->where('ends_at', '>', now())->first()
            : null;

        if ($grant === null) {
            throw AuthError::notHere();
        }

        $this->access->actViaGrant($grant);
        $this->audit->share(['support_grant' => $grant->id]);

        // Visible to the provider in its own trail: who looked, when, at what
        // (at most one entry per grant every five minutes to keep the trail readable).
        if (Cache::add('support-used:'.$grant->id, 1, 300)) {
            $this->audit->record('support_access.used', $grant, meta: ['path' => '/'.$request->path()]);
        }

        return $next($request);
    }
}
