<?php

declare(strict_types=1);

namespace Modules\Delivery\Contracts;

/**
 * Days learners spent at their workplace, for the monthly register. The Logbook module
 * provides it; without it the register has classes only.
 */
interface WorkplaceDays
{
    /**
     * @return array<string, array{days: array<string, int>, mentors: list<string>}> per learner (user id):
     *                                                                               minutes per date, and the user ids of the mentors who sign
     */
    public function forCohort(string $cohortId, string $from, string $to): array;
}
