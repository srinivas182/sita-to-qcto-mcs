<?php

declare(strict_types=1);

namespace Modules\Qualifications\Support;

use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;

/**
 * Checks a qualification before it is published. Errors block publishing; warnings
 * are shown to the editor (the curriculum document is the final word).
 */
final class CatalogueChecks
{
    /**
     * @param  iterable<QualificationModule>  $modules
     * @return array{errors: list<string>, warnings: list<string>, totals: array<string, int>}
     */
    public static function run(Qualification $q, iterable $modules): array
    {
        $errors = [];
        $warnings = [];
        $totals = ['KM' => 0, 'PM' => 0, 'WM' => 0, 'US' => 0, 'fundamental' => 0, 'core' => 0, 'elective' => 0, 'compulsory' => 0];
        $count = 0;

        foreach ($modules as $m) {
            $count++;
            $totals[$m->kind] += $m->credits;
            $totals[$m->group] = ($totals[$m->group] ?? 0) + $m->credits;
        }

        if ($q->saqa_id === null && $q->curriculum_code === null) {
            $errors[] = 'Add the SAQA ID or the curriculum code.';
        }
        if ($count === 0) {
            $errors[] = $q->isLegacy() ? 'Add its unit standards.' : 'Add its knowledge, practical and work experience modules.';
        }
        if ($q->isLegacy() && $q->last_enrolment_on === null) {
            $errors[] = 'Legacy qualifications need their last date for enrolment.';
        }
        if ($q->last_enrolment_on !== null && $q->last_achievement_on !== null && $q->last_achievement_on->lt($q->last_enrolment_on)) {
            $errors[] = 'The last date for achievement is before the last date for enrolment.';
        }

        if ($count > 0 && ! $q->isLegacy()) {
            foreach (['KM' => 'knowledge', 'PM' => 'practical skill', 'WM' => 'work experience'] as $kind => $label) {
                if ($totals[$kind] === 0 && $q->type !== 'skills_programme') {
                    $warnings[] = "No {$label} modules: occupational qualifications normally have all three.";
                }
            }
            $compulsory = $totals['compulsory'];
            if ($compulsory > $q->credits) {
                $errors[] = "Compulsory modules add up to {$compulsory} credits, more than the qualification's {$q->credits}.";
            } elseif ($totals['elective'] === 0 && $compulsory !== $q->credits) {
                $warnings[] = "Modules add up to {$compulsory} credits; the qualification has {$q->credits}.";
            }
        }

        if ($count > 0 && $q->isLegacy()) {
            $rules = $q->credit_rules ?? [];
            foreach (['fundamental', 'core'] as $group) {
                if (isset($rules[$group]) && $totals[$group] < $rules[$group]) {
                    $errors[] = ucfirst($group)." unit standards add up to {$totals[$group]} credits; the rules need {$rules[$group]}.";
                }
            }
            if (isset($rules['elective_min']) && $totals['elective'] < $rules['elective_min']) {
                $errors[] = "Elective unit standards add up to {$totals['elective']} credits; learners need {$rules['elective_min']} to choose from.";
            }
            $minimum = ($rules['fundamental'] ?? 0) + ($rules['core'] ?? 0) + ($rules['elective_min'] ?? 0);
            if ($rules !== [] && $minimum !== $q->credits) {
                $warnings[] = "The credit rules add up to {$minimum}; the qualification has {$q->credits}.";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'totals' => array_filter($totals)];
    }
}
