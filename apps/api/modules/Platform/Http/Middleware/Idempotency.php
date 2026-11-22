<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support for writes (Stripe-style). The mobile outbox sends every
 * change with a key; if the connection drops after the server acted, the retry gets
 * the original response instead of doing the work twice.
 *
 * - Keys are scoped to the tenant, the caller (token or session) and the endpoint.
 * - Reusing a key with a different body is rejected (422).
 * - A concurrent duplicate gets 409 while the first is still running.
 * - Responses below 500 are kept for 24 hours; 5xx are not stored, so they can be retried.
 */
final class Idempotency
{
    private const TTL = 86_400;

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');

        if ($key === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_\-]{8,100}$/', $key)) {
            return $this->error(422, 'idempotency_key_invalid', 'Idempotency-Key must be 8–100 letters, digits, dashes or underscores.');
        }

        $caller = $request->bearerToken() !== null
            ? 'token:'.hash('sha256', (string) $request->bearerToken())
            : ($request->hasSession() ? 'session:'.$request->session()->getId() : 'ip:'.$request->ip());

        $scope = hash('sha256', implode('|', [$this->context->tenantId() ?? 'platform', $caller, $request->method(), $request->path(), $key]));
        $fingerprint = hash('sha256', $request->getContent());
        $store = $this->store();

        $cached = $store->get('idem:'.$scope);

        if (is_array($cached)) {
            if ($cached['fingerprint'] !== $fingerprint) {
                return $this->error(422, 'idempotency_key_reused', 'This Idempotency-Key was already used for a different request.');
            }

            return response((string) $cached['body'], (int) $cached['status'], [...$cached['headers'], 'Idempotent-Replayed' => 'true']);
        }

        $locks = $store->getStore();

        if (! $locks instanceof LockProvider) {
            return $next($request); // stores without locks (none in use) skip the guard
        }

        $lock = $locks->lock('idem-lock:'.$scope, 30);

        if (! $lock->get()) {
            return $this->error(409, 'idempotency_in_progress', 'The same request is still being processed.');
        }

        try {
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                $store->put('idem:'.$scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'headers' => array_filter(['Content-Type' => $response->headers->get('Content-Type')]),
                ], self::TTL);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function store(): Repository
    {
        $name = config('platform.tenancy.cache_store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }

    private function error(int $status, string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
