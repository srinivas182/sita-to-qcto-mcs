<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Access\Services\AccessService;
use Modules\Identity\Models\User;
use Modules\Identity\Services\SignIn;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admins, regulators and TP owners must have an authenticator app or passkey.
 * Until they set one up, only the enrolment, profile and sign-out routes work.
 */
final class RequireMfaEnrolment
{
    public function __construct(
        private readonly AccessService $access,
        private readonly SignIn $signIn,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->access->requiresMfa($user) && ! $this->signIn->hasSecondFactor($user)) {
            return response()->json(['error' => [
                'code' => 'mfa_setup_required',
                'message' => 'Set up an authenticator app or passkey to continue.',
            ]], 403);
        }

        return $next($request);
    }
}
