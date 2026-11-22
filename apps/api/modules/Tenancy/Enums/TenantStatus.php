<?php

declare(strict_types=1);

namespace Modules\Tenancy\Enums;

/**
 * Lifecycle of a training provider on the platform.
 *
 * pending   → applied, awaiting platform checks
 * verified  → documents checked, awaiting activation (package, payment)
 * active    → live; users can sign in
 * suspended → blocked; nobody but the platform can enter
 */
enum TenantStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Active = 'active';
    case Suspended = 'suspended';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Verified, self::Active, self::Suspended],
            self::Verified => [self::Active, self::Suspended],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
        }, true);
    }

    /** Whether users of the tenant may reach its spaces. */
    public function allowsAccess(): bool
    {
        return $this !== self::Suspended;
    }
}
