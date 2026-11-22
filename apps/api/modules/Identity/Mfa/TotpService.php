<?php

declare(strict_types=1);

namespace Modules\Identity\Mfa;

use Illuminate\Support\Str;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\MfaFactor;
use Modules\Identity\Models\User;
use Modules\Identity\Support\IdentityHasher;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app MFA (RFC 6238, 30 s steps, ±1 step tolerance) with replay
 * protection and ten single-use recovery codes.
 */
final class TotpService
{
    public const RECOVERY_CODES = 10;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function hasConfirmed(User $user): bool
    {
        return MfaFactor::query()->where('user_id', $user->id)->where('type', 'totp')->whereNotNull('confirmed_at')->exists();
    }

    /**
     * Start (or restart) enrolment. Replaces any unconfirmed factor.
     *
     * @return array{secret: string, otpauth_url: string}
     */
    public function begin(User $user, string $issuer): array
    {
        if ($this->hasConfirmed($user)) {
            throw AuthError::mfaAlreadyEnrolled();
        }

        $secret = $this->google2fa->generateSecretKey(32);

        MfaFactor::query()->where('user_id', $user->id)->where('type', 'totp')->whereNull('confirmed_at')->delete();

        MfaFactor::query()->create(['user_id' => $user->id, 'type' => 'totp', 'secret' => $secret]);

        $label = $user->email ?? $user->phone_e164 ?? $user->fullName();

        return ['secret' => $secret, 'otpauth_url' => $this->google2fa->getQRCodeUrl($issuer, $label, $secret)];
    }

    /**
     * Confirm enrolment with a first code; returns recovery codes (shown once).
     *
     * @return list<string>
     */
    public function confirm(User $user, string $code): array
    {
        $factor = MfaFactor::query()->where('user_id', $user->id)->where('type', 'totp')->whereNull('confirmed_at')->first();

        if ($factor === null || ! $this->checkCode($factor, $code)) {
            throw AuthError::invalidCode();
        }

        $codes = $this->newRecoveryCodes();
        $factor->forceFill([
            'confirmed_at' => now(),
            'recovery_codes' => array_map(fn (string $c): string => $this->hashRecovery($user, $c), $codes),
        ])->save();

        return $codes;
    }

    /** Verify a code or a recovery code during sign-in. */
    public function verify(User $user, string $code): bool
    {
        $factor = MfaFactor::query()->where('user_id', $user->id)->where('type', 'totp')->whereNotNull('confirmed_at')->first();

        if ($factor === null) {
            return false;
        }

        $clean = strtolower(trim($code));

        if (preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $clean)) {
            return $this->useRecoveryCode($factor, $user, $clean);
        }

        return $this->checkCode($factor, $clean);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $factor = MfaFactor::query()->where('user_id', $user->id)->where('type', 'totp')->whereNotNull('confirmed_at')->firstOrFail();
        $codes = $this->newRecoveryCodes();
        $factor->forceFill(['recovery_codes' => array_map(fn (string $c): string => $this->hashRecovery($user, $c), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        MfaFactor::query()->where('user_id', $user->id)->where('type', 'totp')->get()->each->delete();
    }

    public function remainingRecoveryCodes(User $user): int
    {
        $codes = MfaFactor::query()->where('user_id', $user->id)->where('type', 'totp')->value('recovery_codes');
        $list = is_string($codes) ? json_decode($codes, true) : $codes;

        return is_array($list) ? count($list) : 0;
    }

    private function checkCode(MfaFactor $factor, string $code): bool
    {
        $digits = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($digits) !== 6) {
            return false;
        }

        $timestep = $this->google2fa->verifyKeyNewer($factor->secret, $digits, $factor->last_timestep, 1);

        if ($timestep === false) {
            return false;
        }

        $factor->forceFill(['last_timestep' => is_int($timestep) ? $timestep : $this->google2fa->getTimestamp()])->save();

        return true;
    }

    private function useRecoveryCode(MfaFactor $factor, User $user, string $code): bool
    {
        $hash = $this->hashRecovery($user, $code);
        $remaining = $factor->recovery_codes ?? [];

        foreach ($remaining as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($remaining[$i]);
                $factor->forceFill(['recovery_codes' => array_values($remaining)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5));
        }

        return $codes;
    }

    private function hashRecovery(User $user, string $code): string
    {
        return IdentityHasher::hash('mfa-recovery:'.$user->id, $code);
    }
}
