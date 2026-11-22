<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Modules\Access\Services\AccessService;
use Modules\Audit\AuditLogger;
use Modules\Identity\Devices\DeviceService;
use Modules\Identity\Devices\TokenIssuer;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

/**
 * Finishes any sign-in method: the web gets a session cookie, the app gets device
 * tokens. Also decides where a person may sign in at all.
 */
final class SignIn
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly TenantContext $context,
        private readonly AccessService $access,
        private readonly DeviceService $devices,
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tenant hosts: active members only. Platform hosts: platform staff only.
     */
    public function ensureMayEnter(User $user): void
    {
        if (! $user->isActive()) {
            throw AuthError::accountDisabled();
        }

        $allowed = $this->context->hasTenant() ? $this->access->isMember($user) : $this->access->isPlatformStaff($user);

        if (! $allowed) {
            throw AuthError::notHere();
        }
    }

    /**
     * @param  array{platform: string, name: string, public_key: string, push_token?: string|null}|null  $device
     * @return array<string, mixed>
     */
    public function complete(Request $request, User $user, string $method, ?array $device = null, bool $remember = false): array
    {
        $this->ensureMayEnter($user);

        $this->context->runAsPlatform(fn () => $user->forceFill(['last_login_at' => now()])->saveQuietly());
        $this->audit->record('auth.signed_in', $user, meta: ['method' => $method, 'mode' => $request->hasSession() ? 'session' : 'token']);

        $mfaSetupRequired = $this->access->requiresMfa($user) && ! $this->hasSecondFactor($user);

        if ($request->hasSession()) {
            $guard = $this->auth->guard('web');

            if ($guard instanceof StatefulGuard) {
                $guard->login($user, $remember);
            }

            $request->session()->regenerate();
            $request->session()->put('auth.method', $method);
            $request->session()->put('auth.confirmed_at', time());

            return ['mode' => 'session', 'mfa_setup_required' => $mfaSetupRequired];
        }

        if ($device === null) {
            throw AuthError::deviceRequired();
        }

        if (! $this->context->hasTenant()) {
            // Platform staff use the web console; the app always works inside a provider.
            throw AuthError::notHere();
        }

        $registered = $this->devices->register($user, $device);

        return ['mode' => 'token', 'mfa_setup_required' => $mfaSetupRequired, ...$this->tokens->issue($user, $registered)];
    }

    public function hasSecondFactor(User $user): bool
    {
        return $user->mfaFactors()->whereNotNull('confirmed_at')->exists() || $user->passkeys()->exists();
    }
}
