<?php

declare(strict_types=1);

namespace Modules\Delivery\Contracts;

/** Used when no module records workplace days. */
final class NoWorkplaceDays implements WorkplaceDays
{
    public function forCohort(string $cohortId, string $from, string $to): array
    {
        return [];
    }
}
