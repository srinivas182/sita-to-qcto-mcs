<?php

declare(strict_types=1);

namespace Modules\Logbook\Support;

use Modules\Delivery\Contracts\WorkplaceDays;
use Modules\Employers\Models\Mentor;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookEntry;

/** Workplace days from logbook entries (not sent back or replaced), with the placements' mentors. */
final class LogbookWorkplaceDays implements WorkplaceDays
{
    public function forCohort(string $cohortId, string $from, string $to): array
    {
        $placements = LearnerPlacement::query()->where('cohort_id', $cohortId)->get()->keyBy('id');
        if ($placements->isEmpty()) {
            return [];
        }
        $mentorUsers = Mentor::query()->whereIn('id', $placements->pluck('mentor_id')->filter())->whereNotNull('user_id')->pluck('user_id', 'id');
        $out = [];
        $entries = LogbookEntry::query()->whereIn('learner_placement_id', $placements->keys())->whereIn('status', LogbookEntry::COUNTED)
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->get(['user_id', 'learner_placement_id', 'work_date', 'minutes']);
        foreach ($entries as $e) {
            $day = $e->work_date->toDateString();
            $out[$e->user_id]['days'][$day] = ($out[$e->user_id]['days'][$day] ?? 0) + $e->minutes;
            $mentor = $mentorUsers->get((string) $placements->get($e->learner_placement_id)?->mentor_id);
            $out[$e->user_id]['mentors'] ??= [];
            if (is_string($mentor) && ! in_array($mentor, $out[$e->user_id]['mentors'], true)) {
                $out[$e->user_id]['mentors'][] = $mentor;
            }
        }
        foreach ($out as &$row) {
            ksort($row['days']);
        }

        return $out;
    }
}
