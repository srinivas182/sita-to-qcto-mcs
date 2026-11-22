<?php

declare(strict_types=1);

namespace Modules\Access\Exceptions;

use DomainException;

final class AccessError extends DomainException
{
    private function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function unknownRole(string $role): self
    {
        return new self('role_unknown', "Unknown role \"{$role}\".");
    }

    public static function wrongScope(string $role): self
    {
        return new self('role_scope', "\"{$role}\" cannot be assigned here.");
    }

    public static function notMember(): self
    {
        return new self('not_member', 'This person is not a member of the training provider.');
    }

    public static function centreNotAllowed(string $role): self
    {
        return new self('role_centre', "\"{$role}\" applies to the whole provider and cannot be limited to a centre.");
    }

    public static function unknownCentre(): self
    {
        return new self('centre_unknown', 'That centre does not belong to this training provider.');
    }

    public static function cannotDelegate(string $role): self
    {
        return new self('role_delegation', "You cannot assign \"{$role}\".", 403);
    }

    public static function lastOwner(): self
    {
        return new self('last_owner', 'A training provider must keep at least one owner.');
    }

    public static function invitationInvalid(): self
    {
        return new self('invitation_invalid', 'This invitation is invalid, used or expired.', 404);
    }

    public static function invitationMismatch(): self
    {
        return new self('invitation_mismatch', 'Sign in with the email or phone the invitation was sent to.', 403);
    }

    public static function grantState(string $status): self
    {
        return new self('grant_state', "This support request is already {$status}.", 409);
    }
}
