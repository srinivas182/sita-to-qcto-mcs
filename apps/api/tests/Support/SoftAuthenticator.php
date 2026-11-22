<?php

declare(strict_types=1);

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * A software FIDO2 authenticator (ES256, "none" attestation) for tests. Produces
 * the same JSON a browser's navigator.credentials.create()/get() returns.
 */
final class SoftAuthenticator
{
    private OpenSSLAsymmetricKey $key;

    public string $credentialId;

    public int $counter = 0;

    public ?string $userHandle = null;

    public function __construct()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('Could not create EC key.');
        }

        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /** @param  array<string, mixed>  $options  from /auth/passkeys/options */
    public function create(array $options, string $origin): array
    {
        $rpId = $options['rp']['id'];
        $this->userHandle = Base64UrlSafe::decodeNoPadding($options['user']['id']);

        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $details = openssl_pkey_get_details($this->key);
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $cose = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

        $authData = hash('sha256', $rpId, true)
            .chr(0x01 | 0x04 | 0x40)
            .pack('N', $this->counter)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .(string) $cose;

        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        $id = Base64UrlSafe::encodeUnpadded($this->credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded((string) $clientData),
                'attestationObject' => Base64UrlSafe::encodeUnpadded((string) $attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    /** @param  array<string, mixed>  $options  from /auth/passkeys/login/options */
    public function get(array $options, string $origin): array
    {
        $this->counter++;

        $clientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $authData = hash('sha256', $options['rpId'], true).chr(0x01 | 0x04).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        $id = Base64UrlSafe::encodeUnpadded($this->credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => $this->userHandle !== null ? Base64UrlSafe::encodeUnpadded($this->userHandle) : null,
            ],
        ];
    }
}
