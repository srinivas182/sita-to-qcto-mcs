<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Access\Services\AccessService;
use Modules\Agreements\Events\AgreementCompleted;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Models\Offer;
use Modules\Identity\Models\User;
use Modules\Messaging\Services\MessageService;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Enrolling: when the learner agreement is signed by everyone, the learner joins the
 * cohort and gets the learner role (from then on the provider is billed for them).
 * Enrolment is also what unlocks a course: learners see only what they are enrolled on.
 */
final class EnrolmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly AccessService $access,
        private readonly MessageService $messages,
    ) {}

    public function onAgreementCompleted(AgreementCompleted $event): void
    {
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($event->tenantId)->first());
        if ($tenant === null) {
            return;
        }
        $this->context->runAs($tenant, function () use ($event): void {
            $offer = Offer::query()->where('agreement_id', $event->agreementId)->where('status', 'accepted')->first();
            if ($offer !== null) {
                $this->enrol($offer);
            }
        });
    }

    public function enrol(Offer $offer): Enrolment
    {
        $application = Application::query()->whereKey($offer->application_id)->firstOrFail();
        $intake = Intake::query()->whereKey($application->intake_id)->firstOrFail();
        $cohort = Cohort::query()->whereKey($intake->cohort_id)->firstOrFail();
        /** @var User $learner */
        $learner = $this->context->runAsPlatform(fn () => User::query()->whereKey($application->user_id)->firstOrFail());

        $enrolment = DB::transaction(function () use ($offer, $application, $cohort, $learner): Enrolment {
            $enrolment = Enrolment::query()->firstOrCreate(['cohort_id' => $cohort->id, 'user_id' => $learner->id], [
                'application_id' => $application->id, 'agreement_id' => $offer->agreement_id, 'funding' => $offer->funding, 'enrolled_on' => now()->toDateString(),
            ]);
            $application->forceFill(['status' => 'enrolled'])->save();
            if (! in_array('learner', $this->access->roles($learner), true)) {
                $this->access->assign($learner, 'learner', $cohort->centre_id);
            }

            return $enrolment;
        });
        $this->audit->record('learner.enrolled', $enrolment, [], ['cohort' => $cohort->name, 'funding' => $enrolment->funding]);
        if ($learner->phone_e164 !== null) {
            $tenantId = $this->context->tenantId();
            /** @var Tenant $tenant */
            $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
            $this->messages->notify($learner->phone_e164, "{$tenant->trading_name}: you are enrolled on {$cohort->name}, starting {$cohort->intake_on->format('j F Y')}. Welcome!", 'application');
        }

        return $enrolment;
    }

    public function end(Enrolment $enrolment, string $status, string $reason): Enrolment
    {
        if ($enrolment->status !== 'active' && $enrolment->status !== 'suspended') {
            throw ValidationException::withMessages(['status' => 'This enrolment has already ended.']);
        }
        $enrolment->forceFill(['status' => $status, 'ended_on' => now()->toDateString(), 'reason' => $reason])->save();
        $this->audit->record('learner.'.$status, $enrolment, [], ['reason' => $reason]);

        return $enrolment;
    }

    /**
     * The access rule: may this person use the learning content of this qualification?
     * Only with an active enrolment in a cohort for it.
     */
    public static function mayLearn(string $userId, string $qualificationId): bool
    {
        return Enrolment::query()->where('user_id', $userId)->where('status', 'active')
            ->whereIn('cohort_id', Cohort::query()->where('qualification_id', $qualificationId)->select('id'))->exists();
    }

    /** @return list<array<string, mixed>> the person's courses: only those they are enrolled on */
    public static function courses(string $userId): array
    {
        $enrolments = Enrolment::query()->where('user_id', $userId)->whereIn('status', ['active', 'suspended', 'completed'])->get();
        $cohorts = Cohort::query()->whereIn('id', $enrolments->pluck('cohort_id'))->get()->keyBy('id');
        $quals = Qualification::query()->whereIn('id', $cohorts->pluck('qualification_id'))->get()->keyBy('id');

        return array_values($enrolments->map(function (Enrolment $e) use ($cohorts, $quals): array {
            $c = $cohorts->get($e->cohort_id);
            $q = $c !== null ? $quals->get($c->qualification_id) : null;

            return ['enrolment_id' => $e->id, 'status' => $e->status, 'cohort' => $c?->name, 'starts_on' => $c?->intake_on->toDateString(), 'ends_on' => $c?->planned_end_on->toDateString(),
                'qualification' => $q ? ['id' => $q->id, 'title' => $q->title, 'saqa_id' => $q->saqa_id, 'nqf_level' => $q->nqf_level] : null];
        })->all());
    }
}
