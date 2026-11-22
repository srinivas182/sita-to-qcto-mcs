<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use DomainException;

/** A domain request the platform cannot accept; `reason` is a stable API error code. */
final class DomainError extends DomainException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $host): self
    {
        return new self('domain_invalid', "\"{$host}\" is not a valid domain name.");
    }

    public static function platformDomain(string $host): self
    {
        return new self('domain_reserved', "\"{$host}\" belongs to the platform. Use your own domain.");
    }

    public static function taken(string $host): self
    {
        return new self('domain_taken', "\"{$host}\" is already connected to a training provider.");
    }

    public static function limitReached(int $limit): self
    {
        return new self('domain_limit', "A training provider can connect up to {$limit} own domains.");
    }

    public static function notReady(string $host): self
    {
        return new self('domain_not_ready', "\"{$host}\" must pass DNS checks and have an active certificate first.");
    }

    public static function cannotRemoveSubdomain(): self
    {
        return new self('domain_subdomain_locked', 'The platform subdomain cannot be removed.');
    }

    public static function cannotRemovePrimary(): self
    {
        return new self('domain_primary_locked', 'Make another domain primary before removing this one.');
    }
}
