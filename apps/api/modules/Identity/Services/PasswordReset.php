<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\User;
use Modules\Identity\Notifications\ResetPasswordNotification;
use Modules\Tenancy\Context\TenantContext;

/**
 * Forgotten password, scoped to where the person signs in: on a provider's host only
 * its members can reset; on the platform host only platform staff. The response never
 * reveals whether the email is known.
 */
final class PasswordReset
{
    public function __construct(
        private readonly PasswordLogin $login,
        private readonly TenantContext $context,
        private readonly SignIn $signIn,
    ) {}

    public function send(string $email, string $host): void
    {
        $user = $this->login->findByEmail($email);

        if ($user === null || ! $user->isActive()) {
            return;
        }

        try {
            $this->signIn->ensureMayEnter($user);
        } catch (AuthError) {
            return;
        }

        $token = $this->broker()->createToken($user);
        $url = 'https://'.$host.'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        $name = $this->context->tenant()->trading_name ?? (string) config('app.name');

        $user->notify(new ResetPasswordNotification($url, $name));
    }

    public function reset(string $email, string $token, string $password): User
    {
        $user = $this->login->findByEmail($email);

        if ($user === null || ! $this->broker()->tokenExists($user, $token)) {
            throw AuthError::invalidCode();
        }

        $this->context->runAsPlatform(function () use ($user, $password): void {
            $user->forceFill(['password' => $password])->save();
        });
        $this->broker()->deleteToken($user);

        return $user;
    }

    private function broker(): PasswordBroker
    {
        $broker = Password::broker();

        return $broker instanceof PasswordBroker ? $broker : throw new \RuntimeException('Password broker unavailable.');
    }
}
