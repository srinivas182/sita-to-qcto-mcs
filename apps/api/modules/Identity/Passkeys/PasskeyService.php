<?php

declare(strict_types=1);

namespace Modules\Identity\Passkeys;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\AuthError;
use Modules\Identity\Models\Passkey;
use Modules\Identity\Models\User;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn passkeys (FIDO2): discoverable credentials with user verification
 * (Face ID, fingerprint, Windows Hello). The relying party is the host the person
 * signs in on, so a passkey made on learn.trainingyoungminds.co.za works only there.
 *
 * We request no attestation (privacy-preserving, as Apple, Google and GitHub do)
 * and store only the public key and signature counter.
 */
final class PasskeyService
{
    private SerializerInterface $serializer;

    private AttestationStatementSupportManager $attestation;

    public function __construct(private readonly Repository $cache)
    {
        $this->attestation = AttestationStatementSupportManager::create();
        $this->attestation->add(NoneAttestationStatementSupport::create());
        $this->serializer = (new WebauthnSerializerFactory($this->attestation))->create();
    }

    /** @return array<string, mixed> options for navigator.credentials.create() */
    public function registrationOptions(User $user, string $host, string $rpName): array
    {
        $existing = $user->passkeys()->get()->map(
            fn (Passkey $p): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', Base64UrlSafe::decodeNoPadding($p->credential_id)),
        )->all();

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($rpName, $host),
            PublicKeyCredentialUserEntity::create($user->email ?? $user->phone_e164 ?? $user->id, $user->id, $user->fullName()),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-8), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $existing,
            $this->timeout(),
        );

        $json = $this->toJson($options);
        $this->cache->put($this->registrationKey($user, $host), $json, (int) config('identity.passkeys.challenge_ttl', 300));

        return $this->decode($json);
    }

    public function register(User $user, string $credentialJson, string $origin, string $host, string $name): Passkey
    {
        $key = $this->registrationKey($user, $host);
        $optionsJson = $this->cache->pull($key);

        if (! is_string($optionsJson)) {
            throw AuthError::passkeyFailed();
        }

        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

            if (! $credential->response instanceof AuthenticatorAttestationResponse) {
                throw AuthError::passkeyFailed();
            }

            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies($origin)->creationCeremony())
                ->check($credential->response, $options, $host);
        } catch (AuthError $e) {
            throw $e;
        } catch (Throwable) {
            throw AuthError::passkeyFailed();
        }

        return Passkey::query()->create([
            'user_id' => $user->id,
            'credential_id' => Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            'source' => $this->decode($this->toJson($record)),
            'sign_count' => $record->counter,
            'name' => mb_substr(trim($name) !== '' ? trim($name) : 'Passkey', 0, 100),
            'aaguid' => $record->aaguid->toRfc4122(),
        ]);
    }

    /**
     * Options for navigator.credentials.get() with discoverable credentials (no
     * username needed; the browser offers the person's passkeys).
     *
     * @return array{session: string, options: array<string, mixed>}
     */
    public function loginOptions(string $host): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $host,
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $this->timeout(),
        );

        $session = Str::random(40);
        $json = $this->toJson($options);
        $this->cache->put('passkey:login:'.hash('sha256', $session.'|'.$host), $json, (int) config('identity.passkeys.challenge_ttl', 300));

        return ['session' => $session, 'options' => $this->decode($json)];
    }

    /**
     * Verify an assertion. The passkey's owner must be visible in the current
     * context (i.e. a member of this provider), otherwise sign-in fails.
     */
    public function login(string $session, string $credentialJson, string $origin, string $host): User
    {
        $optionsJson = $this->cache->pull('passkey:login:'.hash('sha256', $session.'|'.$host));

        if (! is_string($optionsJson)) {
            throw AuthError::passkeyFailed();
        }

        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
        } catch (Throwable) {
            throw AuthError::passkeyFailed();
        }

        if (! $credential->response instanceof AuthenticatorAssertionResponse) {
            throw AuthError::passkeyFailed();
        }

        $passkey = Passkey::query()->where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId))->first();
        $user = $passkey !== null ? User::query()->find($passkey->user_id) : null;

        if ($passkey === null || $user === null) {
            throw AuthError::passkeyFailed();
        }

        try {
            $record = $this->serializer->deserialize((string) json_encode($passkey->source), CredentialRecord::class, 'json');

            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies($origin)->requestCeremony())
                ->check($record, $credential->response, $options, $host, $user->id);
        } catch (Throwable) {
            throw AuthError::passkeyFailed();
        }

        $passkey->forceFill([
            'source' => $this->decode($this->toJson($updated)),
            'sign_count' => $updated->counter,
            'last_used_at' => now(),
        ])->save();

        return $user;
    }

    /** @return positive-int */
    private function timeout(): int
    {
        return max(1, (int) config('identity.passkeys.timeout_ms', 60000));
    }

    private function ceremonies(string $origin): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$origin]);
        $factory->setAttestationStatementSupportManager($this->attestation);

        return $factory;
    }

    private function registrationKey(User $user, string $host): string
    {
        return 'passkey:register:'.$user->id.'|'.$host;
    }

    private function toJson(object $value): string
    {
        return $this->serializer->serialize($value, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
