<?php

declare(strict_types=1);

namespace Modules\Cohorts\Services;

use Carbon\CarbonImmutable;
use Modules\Agreements\Models\Agreement;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Cohorts\Models\Placement;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Models\User;
use Modules\People\Models\StaffCredential;
use Modules\People\Services\CredentialService;
use Modules\Qualifications\Models\CentreQualification;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Models\Centre;

/**
 * Whether a cohort may open: a list of pass, warn and fail items, each with a plain
 * reason and where to fix it. A cohort opens only when nothing fails; while open, the
 * same check runs daily so a lapse (an expired registration, say) is caught.
 */
final class Readiness
{
    /** @var list<array{key: string, group: string, status: string, title: string, detail: string, fix: string|null}> */
    private array $items = [];

    public function __construct(private readonly CredentialService $credentials) {}

    /** @return array{ok: bool, counts: array{pass: int, warn: int, fail: int}, items: list<array{key: string, group: string, status: string, title: string, detail: string, fix: string|null}>} */
    public function check(Cohort $cohort): array
    {
        $this->items = [];
        $intake = CarbonImmutable::parse($cohort->intake_on);
        $end = CarbonImmutable::parse($cohort->planned_end_on);
        $qualification = Qualification::query()->whereKey($cohort->qualification_id)->firstOrFail();

        $this->qualification($cohort, $qualification, $intake);
        $this->centre($cohort, $qualification, $intake, $end);
        $this->staff($cohort, $qualification, $intake, $end);
        $this->workplaces($cohort, $qualification, $intake, $end);
        $this->dates($cohort, $intake);

        $count = fn (string $status): int => count(array_filter($this->items, fn (array $i): bool => $i['status'] === $status));
        $counts = ['pass' => $count('pass'), 'warn' => $count('warn'), 'fail' => $count('fail')];

        return ['ok' => $counts['fail'] === 0, 'counts' => $counts, 'items' => $this->items];
    }

    private function qualification(Cohort $cohort, Qualification $q, CarbonImmutable $intake): void
    {
        $label = $q->title.($q->saqa_id ? " (SAQA {$q->saqa_id})" : '');
        $state = $q->enrolmentState($intake);
        $last = $q->last_enrolment_on?->format('j M Y');
        match ($state) {
            'open' => $this->add('qualification.enrolment', 'Qualification', 'pass', 'Open for enrolment', "{$label} takes new learners on the intake date."),
            'closing' => $this->add('qualification.enrolment', 'Qualification', 'warn', 'Enrolment closing soon', "The last date to enrol on {$label} is {$last}. Register learners before then.", 'catalogue'),
            'closed' => $this->add('qualification.enrolment', 'Qualification', 'fail', 'Enrolment closed', "The last date to enrol on {$label} was {$last}, before this intake.", 'catalogue'),
            'ended' => $this->add('qualification.enrolment', 'Qualification', 'fail', 'Qualification ended', "{$label} can no longer be achieved.", 'catalogue'),
            default => $this->add('qualification.enrolment', 'Qualification', 'fail', 'Not in the published catalogue', "{$label} is {$state}.", 'catalogue'),
        };
        if ($q->last_achievement_on !== null && CarbonImmutable::parse($cohort->planned_end_on)->gt($q->last_achievement_on)) {
            $this->add('qualification.achievement', 'Qualification', 'fail', 'Ends after the last achievement date',
                "Learners must achieve {$label} by {$q->last_achievement_on->format('j M Y')}; this cohort is planned to end later.", 'cohort');
        }

        $programme = Programme::query()->where('qualification_id', $q->id)->first();
        if ($programme === null || ! $programme->active) {
            $this->add('qualification.programme', 'Qualification', 'fail', 'Not one of your programmes', 'Add it under Programmes before opening a cohort.', 'programmes');
        } elseif ($programme->modes !== null && $programme->modes !== [] && ! in_array($cohort->mode, $programme->modes, true)) {
            $this->add('qualification.programme', 'Qualification', 'warn', 'Mode not offered', 'Your programme is set up for '.implode(', ', $programme->modes).", not {$cohort->mode}.", 'programmes');
        } else {
            $this->add('qualification.programme', 'Qualification', 'pass', 'One of your programmes', 'Adopted and active.');
        }
    }

    private function centre(Cohort $cohort, Qualification $q, CarbonImmutable $intake, CarbonImmutable $end): void
    {
        $centre = Centre::query()->whereKey($cohort->centre_id)->firstOrFail();
        if ($centre->status !== 'active') {
            $this->add('centre.active', 'Centre', 'fail', 'Centre not active', "{$centre->name} is {$centre->status}.", 'centres');

            return;
        }
        $accreditation = $centre->accreditation_expires_on;
        if ($accreditation !== null && $accreditation->lt($intake)) {
            $this->add('centre.accreditation', 'Centre', 'fail', 'Accreditation ends before intake', "{$centre->name}'s accreditation ended on {$accreditation->format('j M Y')}.", 'centres');
        } elseif ($accreditation !== null && $accreditation->lt($end)) {
            $this->add('centre.accreditation', 'Centre', 'warn', 'Accreditation ends during the cohort', "{$centre->name}'s accreditation ends on {$accreditation->format('j M Y')}. Renew it in time.", 'centres');
        } else {
            $this->add('centre.accreditation', 'Centre', 'pass', 'Centre accredited', $centre->name.($centre->accreditation_number ? " ({$centre->accreditation_number})" : '').($accreditation ? ' until '.$accreditation->format('j M Y') : '').'.');
        }

        $scope = CentreQualification::query()->where('centre_id', $centre->id)->where('qualification_id', $q->id)->first();
        if ($scope === null) {
            $this->add('centre.scope', 'Centre', 'fail', 'Not in the centre\'s scope', "{$centre->name} is not accredited for this qualification. Add it to the centre's scope.", 'centres');
        } elseif ($scope->expires_on !== null && $scope->expires_on->lt($intake)) {
            $this->add('centre.scope', 'Centre', 'fail', 'Scope ends before intake', "The centre's accreditation for this qualification ended on {$scope->expires_on->format('j M Y')}.", 'centres');
        } elseif ($scope->expires_on !== null && $scope->expires_on->lt($end)) {
            $this->add('centre.scope', 'Centre', 'warn', 'Scope ends during the cohort', "The centre's accreditation for this qualification ends on {$scope->expires_on->format('j M Y')}.", 'centres');
        } else {
            $this->add('centre.scope', 'Centre', 'pass', 'In the centre\'s scope', 'The centre is accredited for this qualification.');
        }
    }

    private function staff(Cohort $cohort, Qualification $q, CarbonImmutable $intake, CarbonImmutable $end): void
    {
        $staff = CohortStaff::query()->where('cohort_id', $cohort->id)->get();
        $names = User::query()->whereIn('id', $staff->pluck('user_id'))->get()->mapWithKeys(fn (User $u) => [$u->id => $u->fullName()]);
        foreach (CohortStaff::ROLES as $role) {
            $label = StaffCredential::ROLES[$role];
            $people = $staff->where('role', $role);
            if ($people->isEmpty()) {
                $this->add("staff.{$role}", 'Staff', 'fail', "No {$label} assigned", "Assign at least one {$label} registered for this qualification.", 'cohort');

                continue;
            }
            foreach ($people as $p) {
                $name = (string) $names->get($p->user_id, 'Someone');
                $now = $this->credentials->eligibility($p->user_id, $role, $q->id, $intake);
                if (! $now['ok']) {
                    $this->add("staff.{$role}.{$p->user_id}", 'Staff', 'fail', "{$name} can't act as {$label}", $now['reason'], 'credentials');

                    continue;
                }
                $later = $this->credentials->eligibility($p->user_id, $role, $q->id, $end);
                $later['ok']
                    ? $this->add("staff.{$role}.{$p->user_id}", 'Staff', 'pass', "{$name}, {$label}", $now['reason'])
                    : $this->add("staff.{$role}.{$p->user_id}", 'Staff', 'warn', "{$name}'s registration ends during the cohort", $now['reason'].' Renew it before the planned end.', 'credentials');
            }
        }
        // A moderator must not moderate their own assessments.
        $assessors = $staff->where('role', 'assessor')->pluck('user_id')->all();
        $moderators = $staff->where('role', 'moderator')->pluck('user_id')->all();
        if ($moderators !== [] && array_diff($moderators, $assessors) === [] && count($assessors) === 1) {
            $this->add('staff.independence', 'Staff', 'warn', 'Same person assesses and moderates',
                'The only assessor is also the moderator. Assign another moderator, or another assessor, so nobody moderates their own assessments.', 'cohort');
        }
    }

    private function workplaces(Cohort $cohort, Qualification $q, CarbonImmutable $intake, CarbonImmutable $end): void
    {
        $workModules = QualificationModule::query()->where('qualification_id', $q->id)->where('kind', 'WM')->count();
        $placements = Placement::query()->where('cohort_id', $cohort->id)->get();
        if ($workModules === 0 && $cohort->mode !== 'workplace' && $placements->isEmpty()) {
            $this->add('workplace.needed', 'Workplace', 'pass', 'No workplace places needed', 'This qualification has no work experience modules.');

            return;
        }
        $planned = (int) $placements->sum('planned_learners');
        if ($planned < $cohort->capacity) {
            $this->add('workplace.places', 'Workplace', 'fail', 'Not enough workplace places', "Places planned for {$planned} of {$cohort->capacity} learners. Add approved workplaces.", 'cohort');
        } else {
            $this->add('workplace.places', 'Workplace', 'pass', 'Workplace places for everyone', "{$planned} places for {$cohort->capacity} learners.");
        }

        $sites = EmployerSite::query()->whereIn('id', $placements->pluck('site_id'))->get()->keyBy('id');
        $employers = Employer::query()->whereIn('id', $placements->pluck('employer_id'))->get()->keyBy('id');
        $mentors = Mentor::query()->whereIn('employer_id', $placements->pluck('employer_id'))->where('active', true)->get();
        // Places other open cohorts already hold at these sites, overlapping these dates.
        $taken = Placement::query()->join('cohorts as c', 'c.id', '=', 'cohort_placements.cohort_id')
            ->whereIn('cohort_placements.site_id', $placements->pluck('site_id'))->where('c.id', '!=', $cohort->id)->where('c.status', 'open')
            ->whereDate('c.intake_on', '<=', $end)->whereDate('c.planned_end_on', '>=', $intake)
            ->groupBy('cohort_placements.site_id')->selectRaw('cohort_placements.site_id, sum(planned_learners) as n')->pluck('n', 'site_id');

        foreach ($placements as $p) {
            $site = $sites->get($p->site_id);
            $employer = $employers->get($p->employer_id);
            if ($site === null || $employer === null) {
                continue;
            }
            $where = "{$employer->legal_name}, {$site->name}";
            if (! $site->approvedFor($q->id)) {
                $this->add("workplace.site.{$site->id}", 'Workplace', 'fail', "{$where}: not approved", 'The workplace approval has ended or does not cover this qualification.', 'employers');

                continue;
            }
            $count = $mentors->filter(fn (Mentor $m) => in_array($site->id, $m->site_ids, true))->count();
            $free = $count * $site->ratioFor($q->id) - (int) $taken->get($site->id, 0);
            if ($p->planned_learners > $free) {
                $this->add("workplace.site.{$site->id}", 'Workplace', 'fail', "{$where}: over capacity",
                    "{$p->planned_learners} planned, room for ".max(0, $free)." ({$count} mentor(s) × {$site->ratioFor($q->id)}, less other cohorts). Add mentors or plan fewer.", 'employers');
            } elseif ($site->approval_expires_on !== null && $site->approval_expires_on->lt($end)) {
                $this->add("workplace.site.{$site->id}", 'Workplace', 'warn', "{$where}: approval ends during the cohort", "The approval ends on {$site->approval_expires_on->format('j M Y')}. Ask the employer to renew it.", 'employers');
            } else {
                $this->add("workplace.site.{$site->id}", 'Workplace', 'pass', $where, "Approved; {$p->planned_learners} of ".max(0, $free).' places used.');
            }
            if ($p->mentor_id !== null && ! $mentors->contains(fn (Mentor $m) => $m->id === $p->mentor_id && in_array($site->id, $m->site_ids, true))) {
                $this->add("workplace.mentor.{$site->id}", 'Workplace', 'fail', "{$where}: mentor unavailable", 'The chosen mentor is inactive or no longer at this workplace.', 'employers');
            }
        }

        // Each host employer has signed an agreement with the provider.
        $agreements = Agreement::query()->whereIn('employer_id', $employers->keys())->whereIn('kind', ['employer_mou', 'workplace'])
            ->whereIn('status', ['sent', 'completed'])->get()->groupBy('employer_id');
        foreach ($employers as $employer) {
            $mine = $agreements->get($employer->id) ?? collect();
            match (true) {
                $mine->contains('status', 'completed') => $this->add("agreement.{$employer->id}", 'Agreements', 'pass', "{$employer->legal_name}: agreement signed", 'A signed agreement with this employer is on record.'),
                $mine->isNotEmpty() => $this->add("agreement.{$employer->id}", 'Agreements', 'warn', "{$employer->legal_name}: waiting for signatures", 'An agreement has been sent and is not yet signed by everyone.', 'agreements'),
                default => $this->add("agreement.{$employer->id}", 'Agreements', 'fail', "{$employer->legal_name}: no agreement", 'Send the employer agreement (or a workplace agreement) before placing learners there.', 'agreements'),
            };
        }
    }

    private function dates(Cohort $cohort, CarbonImmutable $intake): void
    {
        if ($cohort->status === 'draft' && $intake->lt(CarbonImmutable::today())) {
            $this->add('dates.intake', 'Dates', 'warn', 'Intake date has passed', "The intake was {$intake->format('j M Y')}. Check the dates before opening.", 'cohort');
        }
        if ($cohort->capacity < 1) {
            $this->add('dates.capacity', 'Dates', 'fail', 'No learner places', 'Set how many learners the cohort takes.', 'cohort');
        }
    }

    private function add(string $key, string $group, string $status, string $title, string $detail, ?string $fix = null): void
    {
        $this->items[] = ['key' => $key, 'group' => $group, 'status' => $status, 'title' => $title, 'detail' => $detail, 'fix' => $status === 'pass' ? null : $fix];
    }
}
