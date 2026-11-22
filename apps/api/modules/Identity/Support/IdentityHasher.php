<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use RuntimeException;

/** Keyed hashes for values that must be findable but never stored in the clear. */
final class IdentityHasher
{
    public static function hash(string $purpose, string $value): string
    {
        $pepper = config('identity.pepper');

        if (! is_string($pepper) || $pepper === '') {
            throw new RuntimeException('IDENTITY_PEPPER (or APP_KEY) must be set.');
        }

        return hash_hmac('sha256', $purpose.'|'.$value, $pepper);
    }

    /** Lookup hash of an identity document: same person → same hash. */
    public static function identityNumber(IdType $type, string $number, ?string $country = null): string
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));

        return self::hash('id:'.$type->value.':'.strtoupper($country ?? ''), $normalised);
    }
}
