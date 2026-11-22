<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use DomainException;
use Modules\Tenancy\Enums\TenantStatus;

final class TenantError extends DomainException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidCode(string $code): self
    {
        return new self('tenant_code_invalid', "\"{$code}\" must be 3–30 lower-case letters, digits or hyphens.");
    }

    public static function reservedCode(string $code): self
    {
        return new self('tenant_code_reserved', "\"{$code}\" is reserved by the platform.");
    }

    public static function codeTaken(string $code): self
    {
        return new self('tenant_code_taken', "\"{$code}\" is already used by another training provider.");
    }

    public static function transition(TenantStatus $from, TenantStatus $to): self
    {
        return new self('tenant_transition_invalid', "A {$from->value} training provider cannot become {$to->value}.");
    }

    public static function noCellCapacity(): self
    {
        return new self('cell_capacity', 'No cell can take a new training provider right now.');
    }
}
