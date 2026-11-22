<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Identity\Devices\TokenIssuer;

/** A phone with an Ed25519 key pair, as the mobile app keeps in Keychain/Keystore. */
final class TestDevice
{
    public string $secret;

    public string $public;

    public function __construct(public string $platform = 'android', public string $name = 'Pixel 8')
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = sodium_crypto_sign_publickey($pair);
    }

    /** @return array{platform: string, name: string, public_key: string} */
    public function payload(): array
    {
        return ['platform' => $this->platform, 'name' => $this->name, 'public_key' => base64_encode($this->public)];
    }

    /** @return array{refresh_token: string, device_id: string, timestamp: int, signature: string} */
    public function refreshRequest(string $deviceId, string $refreshToken, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $signature = sodium_crypto_sign_detached(TokenIssuer::refreshMessage($deviceId, $timestamp, $refreshToken), $this->secret);

        return ['refresh_token' => $refreshToken, 'device_id' => $deviceId, 'timestamp' => $timestamp, 'signature' => base64_encode($signature)];
    }
}
