<?php

declare(strict_types=1);

namespace Modules\Billing\Support;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    /** An invoice is overdue; everything still works. */
    case PastDue = 'past_due';
    /** Overdue too long: records can be seen but not changed. */
    case ReadOnly = 'read_only';
    /** Overdue longest: only billing and sign-in work. Nothing is deleted. */
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /** May the provider change data? */
    public function allowsWrites(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }
}
