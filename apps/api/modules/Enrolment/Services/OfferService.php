<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Models\Template;
use Modules\Agreements\Services\AgreementService;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\ConsentText;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\EnrolmentSettings;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Models\Offer;
use Modules\Identity\Models\Consent;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\PhoneNumber;
use Modules\Messaging\Services\MessageService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Offers: made only when the documents are in and a place is free; accepted with POPIA
 * consent (and a parent or guardian for a learner under 18), which sends the learner
 * agreement. Signing it enrols the learner (EnrolmentService). A lapsed or declined offer
 * moves the waiting list up.
 */
final class OfferService
{
    public const DEFAULT_DAYS = 7;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly DocumentService $documents,
        private readonly MessageService $messages,
        private readonly PushService $pushes,
    ) {}

    /** Places an intake still has: its own number and the cohort's capacity, less offers held and learners enrolled. */
    public function placesLeft(Intake $intake): int
    {
        $cohort = Cohort::query()->whereKey($intake->cohort_id)->firstOrFail();
        $held = Offer::query()->whereIn('application_id', Application::query()->where('intake_id', $intake->id)->select('id'))
            ->where(fn ($q) => $q->where('status', 'accepted')->orWhere(fn ($w) => $w->where('status', 'open')->where('expires_at', '>', now())))->count();
        $enrolled = Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('status', ['active', 'suspended'])->count();
        // An accepted offer becomes an enrolment once signed: count each person once.
        $acceptedThenEnrolled = Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('application_id', Application::query()->where('intake_id', $intake->id)->select('id'))->count();

        return max(0, min($intake->places, $cohort->capacity) - $held - $enrolled + min($acceptedThenEnrolled, $held));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{offer: Offer, link: string}
     */
    public function make(Application $application, array $input, User $by): array
    {
        $data = Validator::make($input, [
            'days' => ['sometimes', 'integer', 'between:1,30'],
            'funding' => ['nullable', Rule::in(Offer::FUNDING)],
        ])->validate();
        if (! in_array($application->status, ['submitted', 'shortlisted', 'waitlisted'], true)) {
            throw ValidationException::withMessages(['status' => 'An offer can only go to an applicant who is new, shortlisted or waiting.']);
        }
        $intake = Intake::query()->whereKey($application->intake_id)->firstOrFail();
        if (! $this->documents->requirements($intake, $application->user_id)['complete']) {
            throw ValidationException::withMessages(['documents' => 'Accept every required document before making an offer.']);
        }
        if ($this->placesLeft($intake) < 1) {
            throw ValidationException::withMessages(['places' => 'There are no places left. Put the applicant on the waiting list.']);
        }
        $template = Template::query()->where('kind', 'learner')->where('status', 'published')->latest('published_at')->first();
        if ($template === null) {
            throw ValidationException::withMessages(['agreement' => 'Publish a learner enrolment agreement under Agreements → Wording first.']);
        }
        if (array_diff($template->parties, ['learner', 'provider']) !== []) {
            throw ValidationException::withMessages(['agreement' => 'The learner enrolment agreement must be between the learner and the provider only. Host employers sign the workplace agreement.']);
        }

        $token = Str::random(48);
        $offer = DB::transaction(function () use ($application, $data, $by, $token): Offer {
            Application::query()->whereKey($application->id)->lockForUpdate()->first();
            $offer = Offer::query()->create(['application_id' => $application->id, 'token_hash' => hash('sha256', $token), 'funding' => $data['funding'] ?? null,
                'expires_at' => now()->addDays((int) ($data['days'] ?? self::DEFAULT_DAYS))->endOfDay(), 'created_by' => $by->id]);
            $application->forceFill(['status' => 'offered', 'waitlist_position' => null, 'decided_at' => now(), 'decided_by' => $by->id])->save();

            return $offer;
        });
        $this->audit->record('offer.made', $offer, [], ['application' => $application->reference, 'expires' => $offer->expires_at->toDateString()]);
        $this->tellOffer($application, $intake, $offer, $token);

        return ['offer' => $offer, 'link' => $this->link($token)];
    }

    /** The provider takes back an open offer (made in error, say); the applicant returns to the shortlist. */
    public function withdraw(Offer $offer, string $reason): void
    {
        $this->open($offer);
        $application = Application::query()->whereKey($offer->application_id)->firstOrFail();
        DB::transaction(function () use ($offer, $application, $reason): void {
            $offer->forceFill(['status' => 'withdrawn', 'responded_at' => now(), 'reason' => $reason])->save();
            $application->forceFill(['status' => 'shortlisted'])->save();
        });
        $this->audit->record('offer.withdrawn', $offer, [], ['reason' => $reason]);
        $this->tell($application->user_id, "the offer {$application->reference} has been withdrawn. We will be in touch.");
    }

    /** @return array<string, mixed> what the learner sees */
    public function present(Offer $offer): array
    {
        $application = Application::query()->whereKey($offer->application_id)->firstOrFail();
        $intake = Intake::query()->whereKey($application->intake_id)->firstOrFail();
        $cohort = Cohort::query()->whereKey($intake->cohort_id)->firstOrFail();
        /** @var User $learner */
        $learner = $this->context->runAsPlatform(fn () => User::query()->whereKey($application->user_id)->firstOrFail());
        $tenantId = $offer->tenant_id;
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $text = ConsentText::current('enrolment');
        $minor = $learner->dob !== null && CarbonImmutable::parse($learner->dob)->addYears(18)->isFuture();
        $status = $offer->status === 'open' && ! $offer->expires_at->isFuture() ? 'lapsed' : $offer->status;

        return [
            'id' => $offer->id, 'status' => $status, 'expires_at' => $offer->expires_at->toIso8601String(), 'funding' => $offer->funding,
            'reference' => $application->reference, 'provider' => $tenant->trading_name, 'learner' => $learner->first_name,
            'course' => ['title' => $intake->title, 'cohort' => $cohort->name, 'starts_on' => $cohort->intake_on->toDateString(), 'ends_on' => $cohort->planned_end_on->toDateString()],
            'needs_guardian' => $minor, 'guardian' => $offer->guardian,
            'consent' => ['version' => $text->version, 'text' => str_replace('{provider}', $tenant->trading_name, $text->text)],
            'agreement_id' => $offer->agreement_id,
            'sign_signer_id' => $offer->agreement_id ? Signer::query()->where('agreement_id', $offer->agreement_id)->where('role', 'learner')->value('id') : null,
        ];
    }

    public function findByToken(string $token): Offer
    {
        return Offer::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    /** @param  array<string, mixed>  $input */
    public function accept(Offer $offer, array $input, string $channel, ?string $ip): Offer
    {
        $this->open($offer);
        $application = Application::query()->whereKey($offer->application_id)->firstOrFail();
        /** @var User $learner */
        $learner = $this->context->runAsPlatform(fn () => User::query()->whereKey($application->user_id)->firstOrFail());
        $minor = $learner->dob !== null && CarbonImmutable::parse($learner->dob)->addYears(18)->isFuture();
        $data = Validator::make($input, [
            'consent' => ['accepted'],
            'consent_version' => ['required', 'integer'],
            'guardian' => [$minor ? 'required' : 'nullable', 'array'],
            'guardian.name' => [$minor ? 'required' : 'nullable', 'string', 'min:3', 'max:150'],
            'guardian.phone' => [$minor ? 'required' : 'nullable', 'string', 'max:20'],
            'guardian.relationship' => [$minor ? 'required' : 'nullable', 'string', 'max:60'],
        ], ['consent.accepted' => 'Please agree to how your information is used.', 'guardian.required' => 'A learner under 18 needs a parent or guardian to sign too.'])->validate();

        $text = ConsentText::current('enrolment');
        if ((int) $data['consent_version'] !== $text->version) {
            throw ValidationException::withMessages(['consent_version' => 'The wording has changed. Read it again before agreeing.']);
        }
        $guardian = null;
        if ($minor) {
            $guardian = ['name' => trim((string) $data['guardian']['name']), 'relationship' => trim((string) $data['guardian']['relationship']),
                'phone' => PhoneNumber::normalise((string) $data['guardian']['phone']) ?? throw ValidationException::withMessages(['guardian.phone' => 'That phone number is not valid.'])];
            if ($guardian['phone'] === $learner->phone_e164) {
                throw ValidationException::withMessages(['guardian.phone' => 'Give your parent or guardian\'s own number.']);
            }
        }

        $accepted = DB::transaction(function () use ($offer, $application, $learner, $text, $guardian, $channel, $ip): Offer {
            Consent::query()->create(['tenant_id' => $offer->tenant_id, 'user_id' => $learner->id, 'purpose' => 'data_sharing_seta', 'version' => 'enrolment-v'.$text->version,
                'granted_at' => now(), 'ip' => $ip, 'consent_text_id' => $text->id, 'given_by' => $guardian ? "{$guardian['name']} ({$guardian['relationship']})" : null, 'channel' => $channel]);

            $intake = Intake::query()->whereKey($application->intake_id)->firstOrFail();
            $cohort = Cohort::query()->whereKey($intake->cohort_id)->firstOrFail();
            /** @var User $by */
            $by = $this->context->runAsPlatform(fn () => User::query()->whereKey($offer->created_by)->firstOrFail());
            $template = Template::query()->where('kind', 'learner')->where('status', 'published')->latest('published_at')->firstOrFail();
            $signers = [
                ['role' => 'learner', 'name' => $learner->fullName(), 'phone' => $learner->phone_e164, 'email' => $learner->email, 'user_id' => $learner->id],
                ['role' => 'provider', 'name' => $by->fullName(), 'email' => $by->email, 'phone' => $by->phone_e164, 'user_id' => $by->id],
            ];
            if ($guardian !== null) {
                $signers[] = ['role' => 'guardian', 'name' => $guardian['name'], 'phone' => $guardian['phone']];
            }
            $agreement = app(AgreementService::class)->send([
                'template_id' => $template->id, 'qualification_id' => $cohort->qualification_id, 'learner_user_id' => $learner->id,
                'start_date' => $cohort->intake_on->toDateString(), 'end_date' => $cohort->planned_end_on->toDateString(), 'signers' => $signers,
            ], $by);

            $offer->forceFill(['status' => 'accepted', 'responded_at' => now(), 'guardian' => $guardian, 'agreement_id' => $agreement->id])->save();
            $application->forceFill(['status' => 'accepted'])->save();
            $this->audit->record('offer.accepted', $offer, [], ['channel' => $channel, 'guardian' => $guardian !== null, 'consent' => $text->version]);

            return $offer;
        });
        // The provider may have every accepted learner checked with Home Affairs in the next batch.
        $checks = app(IdentityCheckService::class);
        if (EnrolmentSettings::current()->identity_auto && $checks->connected() !== null && $learner->id_type === IdType::SaId) {
            $checks->request($learner, null, 'Accepted an offer');
        }

        return $accepted;
    }

    public function decline(Offer $offer, ?string $reason): void
    {
        $this->open($offer);
        $application = Application::query()->whereKey($offer->application_id)->firstOrFail();
        DB::transaction(function () use ($offer, $application, $reason): void {
            $offer->forceFill(['status' => 'declined', 'responded_at' => now(), 'reason' => $reason])->save();
            $application->forceFill(['status' => 'declined', 'reason' => 'Declined the offer'.($reason ? ": {$reason}" : '')])->save();
        });
        $this->audit->record('offer.declined', $offer, [], ['reason' => $reason]);
        $this->moveWaitlist(Intake::query()->whereKey($application->intake_id)->firstOrFail());
    }

    /** Offers past their date lapse; the applicant is told, and the waiting list moves up. */
    public function lapse(Offer $offer): void
    {
        $application = Application::query()->whereKey($offer->application_id)->firstOrFail();
        DB::transaction(function () use ($offer, $application): void {
            $offer->forceFill(['status' => 'lapsed'])->save();
            $application->forceFill(['status' => 'declined', 'reason' => 'The offer was not accepted in time.'])->save();
        });
        $this->audit->record('offer.lapsed', $offer);
        $this->tell($application->user_id, "your offer {$application->reference} has lapsed because it was not accepted in time.");
        $this->moveWaitlist(Intake::query()->whereKey($application->intake_id)->firstOrFail());
    }

    /**
     * A place came free: offer it to the first on the waiting list whose documents are all
     * accepted. (Those still missing documents keep their place in the queue.)
     */
    public function moveWaitlist(Intake $intake): ?Offer
    {
        if ($intake->status === 'draft' || $this->placesLeft($intake) < 1) {
            return null;
        }
        $by = Offer::query()->whereIn('application_id', Application::query()->where('intake_id', $intake->id)->select('id'))->latest()->value('created_by')
            ?? $intake->created_by;
        $user = $by !== null ? $this->context->runAsPlatform(fn () => User::query()->whereKey($by)->first()) : null;
        if ($user === null) {
            return null;
        }
        foreach (Application::query()->where('intake_id', $intake->id)->where('status', 'waitlisted')->orderBy('waitlist_position')->get() as $next) {
            try {
                $made = $this->make($next, [], $user);
                $this->audit->record('waitlist.moved_up', $next, [], ['reference' => $next->reference]);

                return $made['offer'];
            } catch (ValidationException) {
                continue; // documents not complete yet: next in line
            }
        }

        return null;
    }

    public function link(string $token): string
    {
        $tenantId = $this->context->tenantId();
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());

        return 'https://'.$tenant->code.'.'.config('platform.base_domain').'/offer/'.$token;
    }

    private function open(Offer $offer): void
    {
        if (! $offer->isOpen()) {
            throw ValidationException::withMessages(['offer' => $offer->status === 'open' ? 'This offer has lapsed.' : 'This offer is no longer open.']);
        }
    }

    private function tellOffer(Application $application, Intake $intake, Offer $offer, string $token): void
    {
        $this->tell($application->user_id, "good news! You are offered a place on {$intake->title}. Accept by {$offer->expires_at->format('j M')}: ".$this->link($token),
            ['type' => 'offer', 'offer_id' => $offer->id]);
    }

    /** @param  array<string, string>  $push */
    private function tell(string $userId, string $text, array $push = []): void
    {
        $tenantId = $this->context->tenantId();
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $phone = $this->context->runAsPlatform(fn () => User::query()->whereKey($userId)->value('phone_e164'));
        if (is_string($phone)) {
            $this->messages->notify($phone, "{$tenant->trading_name}: {$text}", 'application');
        }
        if ($push !== []) {
            foreach (Device::query()->where('user_id', $userId)->whereNull('revoked_at')->whereNotNull('push_token')->get() as $device) {
                try {
                    $this->pushes->send($device, 'You are offered a place', $tenant->trading_name, $push);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
