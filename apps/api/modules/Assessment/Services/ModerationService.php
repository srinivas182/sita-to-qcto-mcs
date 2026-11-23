<?php

declare(strict_types=1);

namespace Modules\Assessment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Assessment\Models\Appeal;
use Modules\Assessment\Models\AssessmentSettings;
use Modules\Assessment\Models\Report;
use Modules\Assessment\Models\Result;
use Modules\Assessment\Models\Sample;
use Modules\Assessment\Support\ResultHash;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\People\Services\CredentialService;
use Modules\Qualifications\Models\QualificationModule;

/**
 * Internal moderation. Every decision is checked against the provider's policy as it is made:
 * Not yet competent, a new assessor's first decisions and decisions by the class's own
 * facilitator are always sampled; the rest at the policy's share (deterministic, so the
 * choice can be shown later). Results outside the sample are final at once.
 */
final class ModerationService
{
    public function __construct(private readonly AuditLogger $audit, private readonly ResultNotifier $notifier) {}

    public function consider(Result $r): ?Sample
    {
        $cohortId = (string) Enrolment::query()->whereKey($r->enrolment_id)->value('cohort_id');
        $reason = $this->reason($r, $cohortId);
        if ($reason === null) {
            $r->forceFill(['status' => 'final'])->save();

            return null;
        }

        return Sample::query()->create(['result_id' => $r->id, 'cohort_id' => $cohortId, 'module_id' => $r->module_id, 'assessor_id' => $r->assessor_id, 'reason' => $reason]);
    }

    private function reason(Result $r, string $cohortId): ?string
    {
        $policy = AssessmentSettings::current();
        if ($r->outcome === 'nyc') {
            return 'nyc';
        }
        if (Result::query()->where('assessor_id', $r->assessor_id)->whereKeyNot($r->id)->count() < $policy->new_assessor_first) {
            return 'new_assessor';
        }
        if (CohortStaff::query()->where('cohort_id', $cohortId)->where('user_id', $r->assessor_id)->where('role', 'facilitator')->exists()) {
            return 'facilitator';
        }

        return hexdec(substr(hash('sha256', 'sample|'.$r->id), 0, 6)) % 100 < $policy->random_pct ? 'random' : null;
    }

    /**
     * The moderator's decision on a sampled result. Agreeing makes it final; overturning
     * records a new, final result with the other outcome, replacing the assessor's.
     *
     * @param  array<string, mixed>  $input
     */
    public function decide(Sample $s, User $moderator, array $input): Sample
    {
        $data = Validator::make($input, [
            'decision' => ['required', 'in:agree,overturn'],
            'comment' => ['required_if:decision,overturn', 'nullable', 'string', 'max:3000'],
        ], ['comment.required_if' => 'Say why you are overturning the decision.'])->validate();
        if ($s->status !== 'open') {
            throw ValidationException::withMessages(['decision' => 'This sample has already been moderated.']);
        }
        $r = Result::query()->whereKey($s->result_id)->firstOrFail();
        $this->checkModerator($moderator, $r, $s->cohort_id);
        if (! ResultHash::intact($r) && $data['decision'] === 'agree') {
            throw ValidationException::withMessages(['decision' => 'The result changed after the assessor decided it. Overturn it or look into it first.']);
        }
        DB::transaction(function () use ($s, $r, $moderator, $data): void {
            $newId = null;
            if ($data['decision'] === 'overturn') {
                $newId = $this->replace($r, $moderator, (string) $data['comment'])->id;
            } else {
                $r->forceFill(['status' => 'final'])->save();
            }
            $s->forceFill(['status' => 'done', 'moderator_id' => $moderator->id, 'decision' => $data['decision'], 'comment' => $data['comment'] ?? null,
                'new_result_id' => $newId, 'decided_at' => now()])->save();
        });
        $this->audit->record('moderation.'.$data['decision'], $s, [], ['reason' => $s->reason]);
        if ($data['decision'] === 'overturn') {
            $this->notifier->tell($r->user_id, 'Result changed after moderation', 'A moderator reviewed one of your results. Open your results to see it.', ['type' => 'result']);
        }

        return $s;
    }

    /**
     * The signed moderation report for a cohort's module, once every sampled result is done.
     *
     * @param  array<string, mixed>  $input
     */
    public function report(Cohort $c, User $moderator, array $input): Report
    {
        $data = Validator::make($input, [
            'module_id' => ['required', 'ulid'],
            'findings' => ['required', 'string', 'min:10', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm the report is a true account of your moderation.'])->validate();
        $module = QualificationModule::query()->whereKey($data['module_id'])->where('qualification_id', $c->qualification_id)->firstOrFail();
        $eligible = app(CredentialService::class)->eligibility($moderator->id, 'moderator', $c->qualification_id);
        if (! $eligible['ok']) {
            throw ValidationException::withMessages(['moderator' => 'You cannot moderate this qualification: '.$eligible['reason']]);
        }
        $samples = Sample::query()->where('cohort_id', $c->id)->where('module_id', $module->id)->get();
        if ($samples->isEmpty()) {
            throw ValidationException::withMessages(['module_id' => 'Nothing has been sampled for this module yet.']);
        }
        if ($samples->contains('status', 'open')) {
            throw ValidationException::withMessages(['module_id' => 'Moderate every sampled result first.']);
        }
        $summary = $this->summary($c->id, $module->id) + ['samples' => $samples->map(fn (Sample $s) => ['result_id' => $s->result_id, 'reason' => $s->reason, 'decision' => $s->decision])->values()->all()];
        $report = Report::query()->create(['cohort_id' => $c->id, 'module_id' => $module->id, 'moderator_id' => $moderator->id, 'credential_id' => $eligible['credential_id'],
            'summary' => $summary, 'findings' => $data['findings'], 'recommendations' => $data['recommendations'] ?? null, 'signed_at' => now(),
            'hash' => hash('sha256', json_encode([$c->id, $module->id, $moderator->id, $summary, $data['findings'], $data['recommendations'] ?? null], JSON_THROW_ON_ERROR))]);
        $this->audit->record('moderation.report_signed', $report, [], ['sampled' => $samples->count()]);

        return $report;
    }

    /**
     * Decisions, sample size and agreement for a cohort's module, per assessor and in total,
     * with whether the sample meets the policy's share.
     *
     * @return array<string, mixed>
     */
    public function summary(string $cohortId, string $moduleId): array
    {
        $enrolments = Enrolment::query()->where('cohort_id', $cohortId)->pluck('id');
        $results = Result::query()->whereIn('enrolment_id', $enrolments)->where('module_id', $moduleId)->whereNull('replaces_id')->get();
        $samples = Sample::query()->where('cohort_id', $cohortId)->where('module_id', $moduleId)->get()->keyBy('result_id');
        $pct = AssessmentSettings::current()->random_pct;
        $per = $results->groupBy('assessor_id')->map(function ($rs, $assessor) use ($samples, $pct) {
            $sampled = $rs->filter(fn (Result $r) => $samples->has($r->id));

            return ['assessor_id' => $assessor, 'decisions' => $rs->count(), 'sampled' => $sampled->count(),
                'overturned' => $sampled->filter(fn (Result $r) => $samples->get($r->id)?->decision === 'overturn')->count(),
                'compliant' => $rs->count() === 0 || $sampled->count() * 100 >= $pct * $rs->count()];
        })->values()->all();

        return ['decisions' => $results->count(), 'competent' => $results->where('outcome', 'competent')->count(), 'nyc' => $results->where('outcome', 'nyc')->count(),
            'sampled' => $samples->count(), 'moderated' => $samples->where('status', 'done')->count(), 'overturned' => $samples->where('decision', 'overturn')->count(),
            'policy_pct' => $pct, 'assessors' => $per, 'compliant' => collect($per)->every(fn (array $a) => $a['compliant'])];
    }

    /** @param  array<string, mixed>  $input */
    public function appeal(Result $r, User $learner, array $input): Appeal
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:10', 'max:3000']])->validate();
        if ($r->user_id !== $learner->id) {
            abort(404);
        }
        if (! in_array($r->status, Result::STANDING, true)) {
            throw ValidationException::withMessages(['reason' => 'This result has been replaced. Appeal the result that stands.']);
        }
        $policy = AssessmentSettings::current();
        if ($r->decided_at->copy()->addDays($policy->appeal_days)->isPast()) {
            throw ValidationException::withMessages(['reason' => "Appeals must be made within {$policy->appeal_days} days of the result."]);
        }
        if (Appeal::query()->where('result_id', $r->id)->exists()) {
            throw ValidationException::withMessages(['reason' => 'You have already appealed this result.']);
        }
        $cohortId = (string) Enrolment::query()->whereKey($r->enrolment_id)->value('cohort_id');
        $a = Appeal::query()->create(['result_id' => $r->id, 'user_id' => $learner->id, 'cohort_id' => $cohortId, 'reason' => $data['reason'], 'lodged_at' => now(),
            'decide_by' => CarbonImmutable::now(AssessmentService::TZ)->addDays($policy->appeal_decide_days)->toDateString()]);
        $this->audit->record('assessment.appealed', $a);

        return $a;
    }

    /**
     * A moderator other than the assessor decides the appeal: upheld (the result stands)
     * or overturned (a new, final result with the other outcome).
     *
     * @param  array<string, mixed>  $input
     */
    public function decideAppeal(Appeal $a, User $moderator, array $input): Appeal
    {
        $data = Validator::make($input, [
            'outcome' => ['required', 'in:upheld,overturned'],
            'note' => ['required', 'string', 'min:5', 'max:3000'],
        ])->validate();
        if ($a->status !== 'open') {
            throw ValidationException::withMessages(['outcome' => 'This appeal has been decided.']);
        }
        $r = Result::query()->whereKey($a->result_id)->firstOrFail();
        $this->checkModerator($moderator, $r, $a->cohort_id);
        DB::transaction(function () use ($a, $r, $moderator, $data): void {
            $newId = null;
            if ($data['outcome'] === 'overturned') {
                $newId = $this->replace($r, $moderator, (string) $data['note'])->id;
                Sample::query()->where('result_id', $r->id)->where('status', 'open')->update(['status' => 'done', 'moderator_id' => $moderator->id, 'decision' => 'overturn',
                    'comment' => 'Overturned on appeal', 'new_result_id' => $newId, 'decided_at' => now()]);
            }
            $a->forceFill(['status' => $data['outcome'], 'decided_by' => $moderator->id, 'decision_note' => $data['note'], 'new_result_id' => $newId, 'decided_at' => now()])->save();
        });
        $this->audit->record('assessment.appeal_'.$data['outcome'], $a);
        $this->notifier->tell($a->user_id, 'Appeal decided', $data['outcome'] === 'overturned' ? 'Your appeal succeeded. Open your results.' : 'Your appeal was not upheld. Open your results for the reasons.', ['type' => 'result']);

        return $a;
    }

    private function checkModerator(User $moderator, Result $r, string $cohortId): void
    {
        if ($moderator->id === $r->assessor_id) {
            throw ValidationException::withMessages(['moderator' => 'You cannot moderate your own assessment.']);
        }
        $qualification = (string) Cohort::query()->whereKey($cohortId)->value('qualification_id');
        $eligible = app(CredentialService::class)->eligibility($moderator->id, 'moderator', $qualification);
        if (! $eligible['ok']) {
            throw ValidationException::withMessages(['moderator' => 'You cannot moderate this qualification: '.$eligible['reason']]);
        }
    }

    /** A new, final result with the other outcome, replacing the given one. */
    private function replace(Result $r, User $by, string $note): Result
    {
        $r->forceFill(['status' => 'overturned'])->save();
        $n = new Result(['enrolment_id' => $r->enrolment_id, 'user_id' => $r->user_id, 'module_id' => $r->module_id, 'instrument_id' => $r->instrument_id, 'attempt' => $r->attempt,
            'assessor_id' => $by->id, 'credential_id' => null, 'outcome' => $r->outcome === 'competent' ? 'nyc' : 'competent', 'score' => $r->score, 'criteria' => $r->criteria,
            'file_ids' => $r->file_ids, 'feedback' => $note, 'remediation' => null, 'assessed_on' => $r->assessed_on->toDateString(), 'decided_at' => now()->startOfSecond(),
            'status' => 'final', 'replaces_id' => $r->id]);
        $n->hash = ResultHash::of($n);
        $n->save();

        return $n;
    }
}
