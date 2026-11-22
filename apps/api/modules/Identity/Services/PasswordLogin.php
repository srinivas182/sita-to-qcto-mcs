<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Mfa\TotpService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

/**
 * Email + password for staff. When an authenticator app is set up, the password
 * step returns a short-lived MFA token instead of a session.
 */
final class PasswordLogin
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Hasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly TotpService $totp,
        private readonly Repository $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $continuation  data needed to finish sign-in after MFA
     * @return array{user: User, mfa_token: string|null}
     */
    public function attempt(string $email, string $password, array $continuation = []): array
    {
        $key = $this->throttleKey($email);
        $this->throttle->ensureAllowed($key);

        $user = $this->findByEmail($email);

        // Hash even when the account is unknown so timing does not reveal it.
        $valid = $this->hasher->check($password, $user->password ?? $this->dummyHash());

        if ($user === null || $user->password === null || ! $valid) {
            $this->throttle->failed($key);

            throw AuthError::invalidCredentials();
        }

        $this->throttle->cleared($key);

        if ($this->hasher->needsRehash($user->password)) {
            $this->context->runAsPlatform(fn () => $user->forceFill(['password' => $password])->saveQuietly());
        }

        if (! $this->totp->hasConfirmed($user)) {
            return ['user' => $user, 'mfa_token' => null];
        }

        $token = Str::random(48);
        $this->cache->put('mfa:pending:'.hash('sha256', $token), [
            'user_id' => $user->id,
            'tenant_id' => $this->context->tenantId(),
            'attempts' => 0,
            ...$continuation,
        ], (int) config('identity.login.mfa_challenge_ttl', 300));

        return ['user' => $user, 'mfa_token' => $token];
    }

    /**
     * Second step: a TOTP or recovery code for a pending MFA token.
     *
     * @return array{user: User, continuation: array<string, mixed>}
     */
    public function completeMfa(string $mfaToken, string $code): array
    {
        $key = 'mfa:pending:'.hash('sha256', $mfaToken);
        $pending = $this->cache->get($key);

        if (! is_array($pending) || ($pending['tenant_id'] ?? null) !== $this->context->tenantId()) {
            throw AuthError::invalidCode();
        }

        $userId = (string) ($pending['user_id'] ?? '');
        $user = $this->context->runAsPlatform(fn (): ?User => User::query()->whereKey($userId)->first());

        if ($user === null) {
            $this->cache->forget($key);

            throw AuthError::invalidCode();
        }

        if (! $this->totp->verify($user, $code)) {
            $pending['attempts'] = (int) $pending['attempts'] + 1;

            if ($pending['attempts'] >= 5) {
                $this->cache->forget($key);
            } else {
                $this->cache->put($key, $pending, (int) config('identity.login.mfa_challenge_ttl', 300));
            }

            throw AuthError::invalidCode();
        }

        $this->cache->forget($key);
        unset($pending['user_id'], $pending['tenant_id'], $pending['attempts']);

        return ['user' => $user, 'continuation' => $pending];
    }

    /** Members of the current provider; platform staff on platform hosts. */
    public function findByEmail(string $email): ?User
    {
        $email = strtolower(trim($email));
        $query = fn (): ?User => User::query()->whereRaw('lower(email) = ?', [$email])->first();

        return $this->context->hasTenant() ? $query() : $this->context->runAsPlatform($query);
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= $this->hasher->make(Str::random(32));
    }

    private function throttleKey(string $email): string
    {
        return hash('sha256', ($this->context->tenantId() ?? 'platform').'|'.strtolower(trim($email)));
    }
}
