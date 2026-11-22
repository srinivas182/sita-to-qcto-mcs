<?php

declare(strict_types=1);

namespace Modules\Identity\Otp;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\OtpChallenge;
use Modules\Identity\Models\User;
use Modules\Identity\Support\IdentityHasher;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\PhoneNumber;
use Modules\Identity\Support\SaIdNumber;
use Modules\Tenancy\Context\TenantContext;

/**
 * Phone PIN sign-in for learners (and anyone with a phone on file).
 *
 * The person enters a phone number, SA ID or passport number; we send a 6-digit PIN
 * to the phone the training provider holds for them, WhatsApp first and SMS as
 * fallback. The response is identical whether or not the person exists, so the
 * endpoint cannot be used to discover who trains where.
 */
final class OtpService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly OtpDispatcher $dispatcher,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @return array{challenge_id: string, expires_in: int, resend_after: int}
     */
    public function request(string $identifier, ?string $country, ?string $preferredChannel, ?string $ip): array
    {
        $tenant = $this->context->tenant() ?? throw AuthError::notHere();
        $key = IdentityHasher::hash('otp-id', strtolower(trim($identifier)));

        $resend = (int) config('identity.otp.resend_after', 30);

        // Check every limit first, then count, so a rejected request uses no quota.
        $this->limit([
            ['otp:resend:'.$key, 1, $resend],
            ['otp:id:'.$key, (int) config('identity.otp.per_identifier_per_hour', 5), 3600],
            ['otp:ip:'.($ip ?? 'none'), (int) config('identity.otp.per_ip_per_hour', 30), 3600],
        ]);

        $ttl = (int) config('identity.otp.ttl', 300);
        $user = $this->findUser($identifier, $country);

        if ($user === null || $user->phone_e164 === null || ! $user->isActive()) {
            return ['challenge_id' => (string) Str::ulid(), 'expires_in' => $ttl, 'resend_after' => $resend];
        }

        $code = $this->code();
        $challenge = new OtpChallenge([
            'user_id' => $user->id,
            'destination' => $user->phone_e164,
            'channel' => 'pending',
            'purpose' => 'sign_in',
            'code_hash' => '',
            'expires_at' => now()->addSeconds($ttl),
            'ip' => $ip,
        ]);
        $challenge->id = (string) Str::ulid();
        $challenge->code_hash = $this->hashCode($challenge->id, $code);

        $host = $this->context->tenant()?->domains()->where('is_primary', true)->value('hostname');
        $message = "{$code} is your {$tenant->trading_name} sign-in PIN. It expires in ".intdiv($ttl, 60)
            .' minutes. Never share it.'.(is_string($host) ? "\n\n@{$host} #{$code}" : '');

        $channel = $this->dispatcher->deliver($user->phone_e164, $message, $preferredChannel, $code);

        if ($channel === null) {
            throw AuthError::deliveryFailed();
        }

        $challenge->channel = $channel;
        $challenge->save();

        return ['challenge_id' => $challenge->id, 'expires_in' => $ttl, 'resend_after' => $resend];
    }

    public function verify(string $challengeId, string $code, ?string $ip): User
    {
        $this->limit([['otp:verify:'.($ip ?? 'none'), 60, 600]]);

        if (! Str::isUlid($challengeId)) {
            throw AuthError::invalidCode();
        }

        $challenge = OtpChallenge::query()->whereKey($challengeId)->first();

        if ($challenge === null || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
            throw AuthError::invalidCode();
        }

        if ($challenge->attempts >= (int) config('identity.otp.max_attempts', 5)) {
            throw AuthError::invalidCode();
        }

        $challenge->increment('attempts');

        if (! hash_equals($challenge->code_hash, $this->hashCode($challenge->id, preg_replace('/\D/', '', $code) ?? ''))) {
            throw AuthError::invalidCode();
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        $user = User::query()->find($challenge->user_id);

        if ($user === null || ! $user->isActive()) {
            throw AuthError::invalidCode();
        }

        if ($user->phone_verified_at === null) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return $user;
    }

    /** Find a member of the current provider by phone, SA ID or passport number. */
    public function findUser(string $identifier, ?string $country): ?User
    {
        $value = trim($identifier);
        $sa = SaIdNumber::tryParse($value);

        if ($sa !== null) {
            return User::query()->where('id_number_hash', IdentityHasher::identityNumber(IdType::SaId, $sa->value))->first();
        }

        $phone = PhoneNumber::normalise($value);

        if ($phone !== null) {
            return User::query()->where('phone_e164', $phone)->first();
        }

        if ($country !== null && preg_match('/^[A-Za-z0-9]{5,20}$/', $value)) {
            return User::query()
                ->where('id_number_hash', IdentityHasher::identityNumber(IdType::Passport, $value, $country))
                ->first();
        }

        return null;
    }

    /** @param  list<array{0: string, 1: int, 2: int}>  $limits  [key, max, decay seconds] */
    private function limit(array $limits): void
    {
        foreach ($limits as [$key, $max]) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw AuthError::throttled(max(1, $this->limiter->availableIn($key)));
            }
        }

        foreach ($limits as [$key, , $decay]) {
            $this->limiter->hit($key, $decay);
        }
    }

    private function code(): string
    {
        $length = (int) config('identity.otp.length', 6);

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function hashCode(string $challengeId, string $code): string
    {
        return IdentityHasher::hash('otp:'.$challengeId, $code);
    }
}
