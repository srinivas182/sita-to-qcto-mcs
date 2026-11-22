<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Access\Exceptions\AccessError;
use Modules\Files\Exceptions\FileError;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Http\Middleware\SecurityHeaders;
use Modules\Identity\Http\Middleware\StatefulSameOrigin;
use Modules\Platform\Http\Middleware\Idempotency;
use Modules\Settings\Exceptions\SettingsError;
use Modules\Tenancy\Exceptions\DomainError;
use Modules\Tenancy\Exceptions\TenantError;
use Modules\Tenancy\Http\Middleware\ResolveTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        // Every versioned API route runs in a tenant context resolved from the host
        // (or the X-Tenant header on platform hosts), then gets a cookie session if
        // it comes from the same-origin web app. Health checks are outside this group.
        $middleware->prependToGroup('api', [ResolveTenant::class, StatefulSameOrigin::class]);
        $middleware->appendToGroup('api', Idempotency::class);

        // Proxies: Cloudflare → load balancer. Trust forwarded headers from them.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        $json = fn (string $code, string $message, int $status, array $headers = []) => response()->json(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
            $headers,
        );

        $exceptions->render(fn (AuthError $e) => $json(
            $e->reason,
            $e->getMessage(),
            $e->status,
            $e->retryAfter !== null ? ['Retry-After' => (string) $e->retryAfter] : [],
        ));
        $exceptions->render(fn (AccessError $e) => $json($e->reason, $e->getMessage(), $e->status));
        $exceptions->render(fn (DomainError $e) => $json($e->reason, $e->getMessage(), 422));
        $exceptions->render(fn (SettingsError $e) => response()->json(
            ['error' => ['code' => $e->reason, 'message' => $e->getMessage()], 'errors' => (object) $e->errors],
            422,
        ));
        $exceptions->render(fn (FileError $e) => $json($e->reason, $e->getMessage(), $e->status));
        $exceptions->render(fn (TenantError $e) => $json($e->reason, $e->getMessage(), $e->reason === 'cell_capacity' ? 503 : 422));
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $json('unauthenticated', 'Sign in to continue.', 401));
    })->create();
