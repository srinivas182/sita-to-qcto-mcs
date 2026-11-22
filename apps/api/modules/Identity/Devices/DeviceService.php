<?php

declare(strict_types=1);

namespace Modules\Identity\Devices;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\PersonalAccessToken;
use Modules\Identity\Models\RefreshToken;
use Modules\Identity\Models\User;

/**
 * Phones registered for sign-in. Each device holds an Ed25519 key pair in the
 * Keychain / Keystore; we keep only the public key and use it to verify refreshes.
 */
final class DeviceService
{
    /**
     * @param  array{platform: string, name: string, public_key: string, push_token?: string|null}  $data
     */
    public function register(User $user, array $data): Device
    {
        $key = base64_decode($data['public_key'], true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw ValidationException::withMessages(['device.public_key' => 'The device key must be a base64 Ed25519 public key.']);
        }

        $fingerprint = hash('sha256', $key);

        // Re-registering the same key (app reinstall keeps Keychain) reuses the device.
        $device = Device::query()
            ->where('user_id', $user->id)
            ->where('offline_key_fingerprint', $fingerprint)
            ->whereNull('revoked_at')
            ->first();

        $device ??= new Device(['user_id' => $user->id, 'offline_key_fingerprint' => $fingerprint, 'public_key' => base64_encode($key)]);
        $device->fill([
            'platform' => $data['platform'],
            'name' => mb_substr($data['name'], 0, 100),
            'push_token' => $data['push_token'] ?? $device->push_token,
            'last_seen_at' => now(),
        ])->save();

        return $device;
    }

    public function find(User $user, string $deviceId): ?Device
    {
        return Device::query()->where('user_id', $user->id)->whereKey($deviceId)->first();
    }

    /** Sign the device out everywhere; the app wipes local data on its next contact. */
    public function revoke(Device $device): void
    {
        if ($device->isRevoked()) {
            return;
        }

        $device->forceFill(['revoked_at' => now(), 'offline_until' => null])->save();

        PersonalAccessToken::query()->where('device_id', $device->id)->delete();
        RefreshToken::query()->where('device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Verify a detached Ed25519 signature made with the device's private key. */
    public function verifySignature(Device $device, string $message, string $signatureBase64): void
    {
        $signature = base64_decode($signatureBase64, true);
        $key = base64_decode($device->public_key, true);

        if ($signature === false || $key === false || $key === '' || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $message, $key)) {
            throw AuthError::invalidSignature();
        }
    }
}
