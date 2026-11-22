<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Resolution\Hostname;
use Modules\Tenancy\Resolution\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context for the request.
 *
 * - Tenant host (subdomain or verified custom domain) → that tenant.
 * - Platform host (api.<base>, admin.<base>, …) → the `X-Tenant` header if present
 *   (mobile app after the TP code / QR step), otherwise no tenant.
 * - Unknown host → 404; suspended tenant → 403.
 *
 * The context is always reset when the request ends, so long-lived workers
 * (Octane, queues) never carry one request's tenant into the next.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = Hostname::normalise($request->getHost());
        $this->context->clear();

        if (Hostname::isPlatformHost($host)) {
            $code = $request->headers->get((string) config('platform.tenancy.header', 'X-Tenant'));

            if ($code === null || $code === '') {
                $request->attributes->set('tenancy.platform_host', true);

                return $next($request);
            }

            $tenant = $this->resolver->byCode($code);
        } else {
            $tenant = $this->resolver->byHost($host);
        }

        if ($tenant === null) {
            return $this->error(404, 'tenant_not_found', 'No training provider is set up at this address.');
        }

        if (! $tenant->status->allowsAccess()) {
            return $this->error(403, 'tenant_suspended', 'This training provider is currently suspended.');
        }

        $this->context->setTenant($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
