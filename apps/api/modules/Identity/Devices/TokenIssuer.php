<?php

declare(strict_types=1);

namespace Modules\Identity\Devices;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Audit\AuditLogger;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\PersonalAccessToken;
use Modules\Identity\Models\RefreshToken;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

/**
 * Mobile sessions: a short-lived access token (15 min) plus a rotating, single-use
 * refresh token (30 days) bound to the device. Refresh requires a fresh Ed25519
 * signature from the device, so a stolen refresh token alone is useless. Reusing a
 * spent refresh token signs the device out (token-theft signal).
 */
final class TokenIssuer
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @return array{token_type: string, access_token: string, expires_at: string, refresh_token: string, refresh_expires_at: string, device_id: string, offline_until: string}
     */
    public function issue(User $user, Device $device): array
    {
        if ($device->isRevoked()) {
            throw AuthError::deviceRevoked();
        }

        return $this->db->transaction(function () use ($user, $device): array {
            PersonalAccessToken::query()->where('device_id', $device->id)->delete();

            $accessPlain = Str::random(48);
            $accessExpires = now()->addSeconds((int) config('identity.tokens.access_ttl', 900));

            /** @var PersonalAccessToken $access */
            $access = $user->tokens()->create([
                'tenant_id' => $this->context->tenantId(),
                'device_id' => $device->id,
                'name' => 'device:'.$device->platform,
                'token' => hash('sha256', $accessPlain),
                'abilities' => ['*'],
                'expires_at' => $accessExpires,
            ]);

            $refreshPlain = Str::random(64);
            $refresh = RefreshToken::query()->create([
                'device_id' => $device->id,
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $refreshPlain),
                'expires_at' => now()->addSeconds((int) config('identity.tokens.refresh_ttl', 2592000)),
            ]);

            $device->forceFill([
                'offline_until' => now()->addDays((int) config('identity.tokens.offline_days', 14)),
                'last_seen_at' => now(),
            ])->save();

            return [
                'token_type' => 'Bearer',
                'access_token' => $access->getKey().'|'.$accessPlain,
                'expires_at' => $accessExpires->toIso8601String(),
                'refresh_token' => $refresh->id.'.'.$refreshPlain,
                'refresh_expires_at' => $refresh->expires_at->toIso8601String(),
                'device_id' => $device->id,
                'offline_until' => (string) $device->offline_until?->toIso8601String(),
            ];
        });
    }

    /**
     * The message the device signs: "refresh.<device_id>.<unix ts>.<sha256(refresh_token)>".
     */
    public static function refreshMessage(string $deviceId, int $timestamp, string $refreshToken): string
    {
        return 'refresh.'.$deviceId.'.'.$timestamp.'.'.hash('sha256', $refreshToken);
    }

    /**
     * @return array{token_type: string, access_token: string, expires_at: string, refresh_token: string, refresh_expires_at: string, device_id: string, offline_until: string}
     */
    public function refresh(string $refreshToken, string $deviceId, int $timestamp, string $signature): array
    {
        [$id, $plain] = array_pad(explode('.', $refreshToken, 2), 2, '');

        if (! Str::isUlid($id) || $plain === '') {
            throw AuthError::invalidCredentials();
        }

        $record = RefreshToken::query()->whereKey($id)->first();

        if ($record === null || ! hash_equals($record->token_hash, hash('sha256', $plain)) || $record->device_id !== $deviceId) {
            throw AuthError::invalidCredentials();
        }

        $device = Device::query()->whereKey($record->device_id)->first();

        if ($device === null || $device->isRevoked() || $record->revoked_at !== null) {
            throw AuthError::deviceRevoked();
        }

        if (abs(time() - $timestamp) > (int) config('identity.tokens.signature_skew', 300)) {
            throw AuthError::invalidSignature();
        }

        $this->devices->verifySignature($device, self::refreshMessage($deviceId, $timestamp, $refreshToken), $signature);

        if ($record->used_at !== null) {
            $this->devices->revoke($device);
            $this->audit->record('auth.refresh_reused', $device, meta: ['refresh_token' => $record->id]);

            throw AuthError::tokenReused();
        }

        if ($record->expires_at->isPast()) {
            throw AuthError::invalidCredentials();
        }

        $user = User::query()->find($record->user_id);

        if ($user === null || ! $user->isActive()) {
            throw AuthError::accountDisabled();
        }

        $record->forceFill(['used_at' => now()])->save();
        $pair = $this->issue($user, $device);
        $record->forceFill(['replaced_by' => explode('.', $pair['refresh_token'], 2)[0]])->save();

        return $pair;
    }
}
