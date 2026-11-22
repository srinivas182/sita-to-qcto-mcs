<?php

declare(strict_types=1);

namespace Modules\Identity\Exceptions;

use DomainException;

/**
 * Authentication failure with a stable API code. Messages never reveal whether an
 * account exists.
 */
final class AuthError extends DomainException
{
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 'These details do not match our records.', 422);
    }

    public static function invalidCode(): self
    {
        return new self('invalid_code', 'That code is incorrect or has expired.', 422);
    }

    public static function throttled(int $seconds): self
    {
        return new self('too_many_attempts', "Too many attempts. Try again in {$seconds} seconds.", 429, $seconds);
    }

    public static function deliveryFailed(): self
    {
        return new self('delivery_failed', 'We could not send a PIN right now. Please try again shortly.', 503);
    }

    public static function mfaRequired(): self
    {
        return new self('mfa_required', 'Confirm with your authenticator app or passkey.', 401);
    }

    public static function mfaAlreadyEnrolled(): self
    {
        return new self('mfa_enrolled', 'An authenticator app is already set up. Remove it first to replace it.', 409);
    }

    public static function deviceRequired(): self
    {
        return new self('device_required', 'Sign-in from the app must include the device.', 422);
    }

    public static function deviceRevoked(): self
    {
        return new self('device_revoked', 'This device has been signed out. Local data must be removed.', 401);
    }

    public static function invalidSignature(): self
    {
        return new self('invalid_signature', 'The device signature is not valid.', 401);
    }

    public static function tokenReused(): self
    {
        return new self('token_reused', 'This sign-in was used twice; the device has been signed out for safety.', 401);
    }

    public static function passkeyFailed(): self
    {
        return new self('passkey_failed', 'The passkey could not be verified.', 422);
    }

    public static function accountDisabled(): self
    {
        return new self('account_disabled', 'This account is disabled. Contact your training provider.', 403);
    }

    public static function notHere(): self
    {
        return new self('not_member', 'You do not have access to this training provider.', 403);
    }
}
