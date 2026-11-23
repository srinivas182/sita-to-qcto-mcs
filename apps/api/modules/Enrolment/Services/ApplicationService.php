<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Models\Offer;
use Modules\Enrolment\Support\Questions;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Otp\OtpDispatcher;
use Modules\Identity\Support\IdentityHasher;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\MembershipStatus;
use Modules\Identity\Support\PhoneNumber;
use Modules\Identity\Support\SaIdNumber;
use Modules\Messaging\Services\MessageService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Applying to an intake and moving applications along. A public applicant proves their
 * phone with a PIN first; they become a member with no role (so they can sign in and
 * follow their application) and get the learner role only when enrolled.
 */
final class ApplicationService
{
    private const CODE_TTL = 600;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly OtpDispatcher $otp,
        private readonly MessageService $messages,
    ) {}

    /** @return array{challenge_id: string, channel: string|null} */
    public function requestCode(Intake $intake, string $phone): array
    {
        $this->accepting($intake);
        $e164 = PhoneNumber::normalise($phone) ?? throw ValidationException::withMessages(['phone' => 'That mobile number is not valid.']);
        // Per phone, not per IP: applicants at an open day share the provider's Wi-Fi.
        $key = 'apply-otp-phone:'.IdentityHasher::hash('apply-phone', $e164);
        if (RateLimiter::tooManyAttempts($key.':30s', 1) || RateLimiter::tooManyAttempts($key.':1h', 5)) {
            throw ValidationException::withMessages(['phone' => 'Wait a moment before asking for another PIN.']);
        }
        RateLimiter::hit($key.':30s', 30);
        RateLimiter::hit($key.':1h', 3600);
        $tenant = $this->tenant();
        $challenge = (string) Str::ulid();
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $channel = $this->otp->deliver($e164, "{$code} is your PIN to apply to {$tenant->trading_name}. Do not share it.", null, $code);
        Cache::put('apply-otp:'.$challenge, ['phone' => $e164, 'intake' => $intake->id, 'hash' => IdentityHasher::hash('apply-otp:'.$challenge, $code), 'tries' => 0], self::CODE_TTL);

        return ['challenge_id' => $challenge, 'channel' => $channel];
    }

    /** @param  array<string, mixed>  $input */
    public function applyPublic(Intake $intake, array $input, ?string $ip): Application
    {
        $this->accepting($intake);
        $data = Validator::make($input, [
            'challenge_id' => ['required', 'ulid'],
            'code' => ['required', 'string', 'max:10'],
            'first_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_name' => ['required', 'string', 'min:1', 'max:100'],
            'id_type' => ['required', Rule::in([IdType::SaId->value, IdType::Passport->value])],
            'id_number' => ['required', 'string', 'max:30'],
            'id_country' => ['required_if:id_type,passport', 'nullable', 'string', 'size:2'],
            'dob' => ['required_if:id_type,passport', 'nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'answers' => ['sometimes', 'array'],
        ])->validate();

        // The PIN proves the phone; it works once.
        $key = 'apply-otp:'.$data['challenge_id'];
        $challenge = Cache::get($key);
        if (! is_array($challenge) || $challenge['intake'] !== $intake->id) {
            throw ValidationException::withMessages(['code' => 'Ask for a PIN first, or a new one if it has expired.']);
        }
        if ($challenge['tries'] >= 5) {
            Cache::forget($key);
            throw ValidationException::withMessages(['code' => 'Too many wrong PINs. Ask for a new one.']);
        }
        if (! hash_equals($challenge['hash'], IdentityHasher::hash($key, preg_replace('/\D/', '', (string) $data['code']) ?? ''))) {
            Cache::put($key, ['tries' => $challenge['tries'] + 1] + $challenge, self::CODE_TTL);
            throw ValidationException::withMessages(['code' => 'That PIN is not right.']);
        }
        Cache::forget($key);
        $phone = (string) $challenge['phone'];

        [$type, $number, $dob] = $this->identity($data);
        $answers = Questions::answers($intake->questions ?? [], (array) ($data['answers'] ?? []));
        $this->ageAllowed($intake, $dob);

        return DB::transaction(function () use ($intake, $data, $phone, $type, $number, $dob, $answers, $ip): Application {
            $user = $this->person($phone, $type, $number, $data['id_country'] ?? null, $dob, $data);
            $this->join($user);
            $application = $this->create($intake, $user, $answers, 'web');
            $this->audit->record('application.submitted', $application, [], ['ip' => $ip, 'source' => 'web']);

            return $application;
        });
    }

    /** @param  array<string, mixed>  $answers */
    public function applyAsMember(Intake $intake, User $user, array $answers): Application
    {
        $this->accepting($intake);
        if ($user->id_number === null) {
            throw ValidationException::withMessages(['id_number' => 'Add your ID number to your profile first.']);
        }
        $this->ageAllowed($intake, $user->dob !== null ? CarbonImmutable::parse($user->dob) : null);
        $application = $this->create($intake, $user, Questions::answers($intake->questions ?? [], $answers), 'app');
        $this->audit->record('application.submitted', $application, [], ['source' => 'app']);

        return $application;
    }

    /** Shortlist, waitlist, decline or reopen; the applicant is told by WhatsApp or SMS. */
    public function decide(Application $application, string $action, ?string $reason, User $by): Application
    {
        $allowed = [
            'shortlist' => ['submitted', 'waitlisted'],
            'waitlist' => ['submitted', 'shortlisted'],
            'decline' => ['submitted', 'shortlisted', 'waitlisted'],
            'reopen' => ['declined'],
        ];
        if (! in_array($application->status, $allowed[$action] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'That step is not possible from where this application is.']);
        }
        if ($action === 'decline' && ($reason === null || mb_strlen(trim($reason)) < 5)) {
            throw ValidationException::withMessages(['reason' => 'Give the applicant a reason.']);
        }

        DB::transaction(function () use ($application, $action, $reason, $by): void {
            $status = ['shortlist' => 'shortlisted', 'waitlist' => 'waitlisted', 'decline' => 'declined', 'reopen' => 'submitted'][$action];
            $position = $status === 'waitlisted'
                ? (int) Application::query()->where('intake_id', $application->intake_id)->max('waitlist_position') + 1
                : null;
            $application->forceFill(['status' => $status, 'reason' => $reason, 'waitlist_position' => $position, 'decided_at' => now(), 'decided_by' => $by->id])->save();
        });
        $this->audit->record('application.'.$application->status, $application, [], ['reason' => $reason]);
        $this->tell($application);

        return $application;
    }

    public function withdraw(Application $application): void
    {
        if (! in_array($application->status, ['submitted', 'shortlisted', 'waitlisted', 'offered'], true)) {
            throw ValidationException::withMessages(['status' => 'This application can no longer be withdrawn.']);
        }
        $hadOffer = $application->status === 'offered';
        DB::transaction(function () use ($application): void {
            Offer::query()->where('application_id', $application->id)->where('status', 'open')
                ->update(['status' => 'withdrawn', 'responded_at' => now(), 'reason' => 'The applicant withdrew.']);
            $application->forceFill(['status' => 'withdrawn', 'waitlist_position' => null])->save();
        });
        $this->audit->record('application.withdrawn', $application);
        if ($hadOffer) {
            app(OfferService::class)->moveWaitlist(Intake::query()->whereKey($application->intake_id)->firstOrFail());
        }
    }

    /** @param  array<string, string|bool>  $answers */
    private function create(Intake $intake, User $user, array $answers, string $source): Application
    {
        if (Application::query()->where('intake_id', $intake->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['application' => 'You have already applied for this intake.']);
        }

        return Application::query()->create([
            'intake_id' => $intake->id, 'user_id' => $user->id, 'reference' => 'APP-'.strtoupper(Str::random(6)),
            'answers' => $answers, 'source' => $source, 'submitted_at' => now(),
        ]);
    }

    /**
     * Find the person by ID number or phone, or create them. An ID number held by someone
     * with another phone is refused, never merged: that person signs in instead.
     *
     * @param  array<string, mixed>  $data
     */
    private function person(string $phone, IdType $type, string $number, ?string $country, ?CarbonImmutable $dob, array $data): User
    {
        return $this->context->runAsPlatform(function () use ($phone, $type, $number, $country, $dob, $data): User {
            $hash = IdentityHasher::identityNumber($type, $number, $type === IdType::Passport ? strtoupper((string) $country) : null);
            $byId = User::query()->where('id_number_hash', $hash)->first();
            $byPhone = User::query()->where('phone_e164', $phone)->first();
            if ($byId !== null && $byId->phone_e164 !== $phone) {
                throw ValidationException::withMessages(['id_number' => 'This ID number is registered with another mobile number. Apply with that number, or ask the training provider for help.']);
            }
            if ($byPhone !== null && $byPhone->id_number_hash !== null && $byPhone->id_number_hash !== $hash) {
                throw ValidationException::withMessages(['id_number' => 'This mobile number belongs to someone with a different ID number.']);
            }
            $user = $byId ?? $byPhone ?? User::query()->create([
                'first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']),
                'phone_e164' => $phone, 'email' => isset($data['email']) ? mb_strtolower((string) $data['email']) : null,
            ]);
            if ($user->id_number_hash === null) {
                $user->setIdentityNumber($type, $number, $type === IdType::Passport ? strtoupper((string) $country) : null);
                $user->dob = $dob === null ? null : Carbon::instance($dob);
                $user->save();
            }

            return $user;
        });
    }

    private function join(User $user): void
    {
        $membership = UserTenant::query()->firstOrNew(['user_id' => $user->id]);
        if ($membership->exists && $membership->status === MembershipStatus::Suspended) {
            throw ValidationException::withMessages(['application' => 'Your account with this provider is suspended. Please contact them.']);
        }
        $membership->fill(['status' => MembershipStatus::Active, 'joined_at' => $membership->joined_at ?? now()])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: IdType, 1: string, 2: CarbonImmutable|null}
     */
    private function identity(array $data): array
    {
        $type = IdType::from((string) $data['id_type']);
        if ($type === IdType::SaId) {
            $sa = SaIdNumber::tryParse((string) $data['id_number']) ?? throw ValidationException::withMessages(['id_number' => 'That is not a valid South African ID number. Check the 13 digits.']);

            return [$type, $sa->value, CarbonImmutable::instance($sa->dateOfBirth)];
        }
        $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $data['id_number']));
        if (strlen($number) < 5) {
            throw ValidationException::withMessages(['id_number' => 'That passport number is too short.']);
        }

        return [$type, $number, CarbonImmutable::parse((string) $data['dob'])];
    }

    private function ageAllowed(Intake $intake, ?CarbonImmutable $dob): void
    {
        if ($dob === null || ($intake->min_age === null && $intake->max_age === null)) {
            return;
        }
        $age = (int) floor($dob->diffInYears(CarbonImmutable::today())); // whole years, as people count them
        if ($intake->min_age !== null && $age < $intake->min_age) {
            throw ValidationException::withMessages(['id_number' => "Applicants must be at least {$intake->min_age}."]);
        }
        if ($intake->max_age !== null && $age > $intake->max_age) {
            throw ValidationException::withMessages(['id_number' => "Applicants must be {$intake->max_age} or younger."]);
        }
    }

    private function accepting(Intake $intake): void
    {
        if (! $intake->accepting()) {
            throw ValidationException::withMessages(['intake' => 'This intake is not taking applications now.']);
        }
    }

    private function tell(Application $application): void
    {
        $phone = $this->context->runAsPlatform(fn () => User::query()->whereKey($application->user_id)->value('phone_e164'));
        if (! is_string($phone)) {
            return;
        }
        $tenant = $this->tenant();
        $intake = Intake::query()->whereKey($application->intake_id)->value('title');
        $text = match ($application->status) {
            'shortlisted' => "{$tenant->trading_name}: good news, your application {$application->reference} for {$intake} is shortlisted. We will be in touch.",
            'waitlisted' => "{$tenant->trading_name}: your application {$application->reference} for {$intake} is on the waiting list (number {$application->waitlist_position}). We will tell you if a place opens.",
            'declined' => "{$tenant->trading_name}: your application {$application->reference} for {$intake} was not successful. Reason: {$application->reason}",
            default => null,
        };
        if ($text !== null) {
            $this->messages->notify($phone, $text, 'application');
        }
    }

    private function tenant(): Tenant
    {
        $id = $this->context->tenantId();

        /** @var Tenant */
        return $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($id)->firstOrFail());
    }
}
