<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\SubscriptionStatus;
use Modules\Tenancy\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the plan's status inside a provider:
 *  - read-only (and ended plans): reading works, changes are refused;
 *  - suspended: only sign-in, profile and billing work.
 * Billing, sign-in and public paths always work, so a provider can always pay.
 * Providers without a plan (e.g. launch partners) are not limited.
 */
final class EnforceSubscription
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasTenant() || $request->is(...(array) config('billing.always_allowed'))) {
            return $next($request);
        }

        $status = Subscription::query()->value('status');
        $status = $status instanceof SubscriptionStatus ? $status : (is_string($status) ? SubscriptionStatus::tryFrom($status) : null);

        if ($status === null) {
            return $next($request);
        }

        if ($status === SubscriptionStatus::Suspended) {
            return $this->refuse('subscription_suspended', 'This account is suspended until the overdue invoice is paid. Your records are safe.');
        }

        if (! $status->allowsWrites() && ! $request->isMethodSafe()) {
            return $this->refuse('subscription_read_only', 'This account is read-only until the overdue invoice is paid. You can still view everything.');
        }

        return $next($request);
    }

    private function refuse(string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], 402);
    }
}
