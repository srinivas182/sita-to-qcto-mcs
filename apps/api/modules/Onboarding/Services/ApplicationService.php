<?php

declare(strict_types=1);

namespace Modules\Onboarding\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Access\Models\PlatformRoleAssignment;
use Modules\Access\Services\InvitationService;
use Modules\Audit\AuditLogger;
use Modules\Billing\Models\Package;
use Modules\Billing\Services\SubscriptionService;
use Modules\Files\Models\File;
use Modules\Files\Services\FileService;
use Modules\Files\Storage\UploadTarget;
use Modules\Identity\Models\User;
use Modules\Identity\Support\PhoneNumber;
use Modules\Messaging\Services\MessageService;
use Modules\Onboarding\Models\Application;
use Modules\Onboarding\Models\ApplicationFile;
use Modules\Onboarding\Notifications\ApplicationDecided;
use Modules\Onboarding\Notifications\ApplicationEmailCode;
use Modules\Onboarding\Notifications\ApplicationNeedsInfo;
use Modules\Onboarding\Notifications\ApplicationReceived;
use Modules\Onboarding\Notifications\ApplicationToReview;
use Modules\Onboarding\Support\Identifiers;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Exceptions\TenantError;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDomain;
use Modules\Tenancy\Services\TenantService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Applications to join, from first contact to a live provider. Platform context.
 *
 *  start → verify email → save steps (any order, resumable) → upload documents →
 *  submit (automatic checks must pass) → review (checklist) → approve / request
 *  info / decline. Approval creates the provider, its subdomain, first centre,
 *  plan trial and billing details, and invites the contact as owner.
 */
final class ApplicationService
{
    public const CONSENT_VERSION = '2026-11';

    /** Checks a reviewer confirms by hand before approving. */
    public const CHECKLIST = [
        'cipc_verified' => 'Company found on the CIPC register under this number and name',
        'letter_matches' => 'Accreditation letter matches the SDP number, name and expiry',
        'scope_confirmed' => 'Qualifications applied for are within the accreditation scope',
        'contact_confirmed' => 'Contact person is authorised to act for the organisation',
    ];

    /** Fields an applicant may save, with their rules. */
    private const FIELDS = [
        'first_name' => ['string', 'max:100'],
        'last_name' => ['string', 'max:100'],
        'phone' => ['nullable', 'string', 'max:20'],
        'legal_name' => ['string', 'max:200'],
        'trading_name' => ['nullable', 'string', 'max:200'],
        'registration_number' => ['nullable', 'string', 'max:20'],
        'vat_number' => ['nullable', 'string', 'max:20'],
        'website' => ['nullable', 'url:https,http', 'max:200'],
        'sdp_number' => ['nullable', 'string', 'max:40'],
        'accreditation_expires_on' => ['nullable', 'date'],
        'scope' => ['nullable', 'array', 'max:50'],
        'scope.*' => ['string', 'regex:/^\d{4,8}$/'],
        'centre_name' => ['nullable', 'string', 'max:200'],
        'address_line1' => ['nullable', 'string', 'max:200'],
        'city' => ['nullable', 'string', 'max:100'],
        'province' => ['nullable', 'string', 'max:100'],
        'postal_code' => ['nullable', 'string', 'max:10'],
        'preferred_code' => ['nullable', 'string', 'max:30'],
        'package_id' => ['nullable', 'ulid'],
        'interval' => ['nullable', 'in:month,year'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{application: Application, token: string}
     */
    public function start(array $input, ?string $ip): array
    {
        $data = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'legal_name' => ['required', 'string', 'max:200'],
        ])->validate();

        $token = Str::random(48);

        $application = $this->platform(fn () => Application::query()->create([
            ...$data,
            'email' => strtolower(trim((string) $data['email'])),
            'reference' => 'APP-'.strtoupper(Str::random(8)),
            'status' => 'draft',
            'token_hash' => hash('sha256', $token),
            'ip' => $ip,
        ]));

        $this->sendEmailCode($application);

        return ['application' => $application, 'token' => $token];
    }

    public function find(string $id, ?string $token): Application
    {
        $application = $this->platform(fn () => Application::query()->find($id));

        if ($application === null || $token === null || ! hash_equals($application->token_hash, hash('sha256', $token))) {
            throw new NotFoundHttpException('Application not found.');
        }

        return $application;
    }

    public function sendEmailCode(Application $application): void
    {
        $code = (string) random_int(100000, 999999);

        $this->platform(fn () => $application->forceFill([
            'email_code_hash' => hash_hmac('sha256', $code, (string) config('identity.pepper')),
            'email_code_expires_at' => now()->addMinutes(30),
            'email_code_attempts' => 0,
        ])->save());

        Notification::route('mail', $application->email)->notify(new ApplicationEmailCode($application, $code));
    }

    public function verifyEmail(Application $application, string $code): Application
    {
        if ($application->email_verified_at !== null) {
            return $application;
        }

        $ok = $application->email_code_hash !== null
            && $application->email_code_expires_at?->isFuture()
            && $application->email_code_attempts < 5
            && hash_equals($application->email_code_hash, hash_hmac('sha256', trim($code), (string) config('identity.pepper')));

        $this->platform(fn () => $ok
            ? $application->forceFill(['email_verified_at' => now(), 'email_code_hash' => null])->save()
            : $application->forceFill(['email_code_attempts' => $application->email_code_attempts + 1])->save());

        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'That code is not right or has expired. Ask for a new one.']);
        }

        return $application;
    }

    /** @param  array<string, mixed>  $input */
    public function update(Application $application, array $input): Application
    {
        if (! $application->editable()) {
            throw ValidationException::withMessages(['status' => 'This application can no longer be changed.']);
        }

        $rules = array_intersect_key(self::FIELDS, $input + ['scope.*' => true]);
        $data = Validator::make($input, $rules, ['scope.*.regex' => 'Use the SAQA qualification ID (digits only).'])->validate();

        foreach (['registration_number', 'sdp_number', 'vat_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Identifiers::normalise($data[$field]);
            }
        }
        if (array_key_exists('preferred_code', $data) && is_string($data['preferred_code'])) {
            $data['preferred_code'] = strtolower(trim($data['preferred_code']));
        }

        $this->platform(fn () => $application->fill($data)->save());

        return $application;
    }

    /**
     * Begin uploading a document for the application (platform-owned file).
     *
     * @param  array{kind: string, name: string, mime: string, size: int}  $input
     * @return array{file: File, upload: UploadTarget|null}
     */
    public function attach(Application $application, array $input): array
    {
        if (! $application->editable()) {
            throw ValidationException::withMessages(['status' => 'This application can no longer be changed.']);
        }

        $result = $this->platform(fn () => app(FileService::class)->create([
            'purpose' => 'application_document', 'name' => $input['name'], 'mime' => $input['mime'], 'size' => $input['size'],
        ], null));

        $this->platform(fn () => ApplicationFile::query()->create(['application_id' => $application->id, 'file_id' => $result['file']->id, 'kind' => $input['kind']]));

        return $result;
    }

    /** @return list<array{id: string, kind: string, name: string, size: int, status: string}> */
    public function files(Application $application): array
    {
        return $this->platform(function () use ($application): array {
            $links = ApplicationFile::query()->where('application_id', $application->id)->get();
            $files = File::query()->whereIn('id', $links->pluck('file_id'))->get()->keyBy('id');

            $out = [];
            foreach ($links as $link) {
                $file = $files->get($link->file_id);
                if ($file instanceof File) {
                    $out[] = ['id' => $link->file_id, 'kind' => $link->kind, 'name' => $file->original_name, 'size' => $file->size, 'status' => $file->status->value];
                }
            }

            return $out;
        });
    }

    /**
     * The automatic checks: shown live while applying, and to the reviewer.
     *
     * @return array<string, array{ok: bool, required: bool, message: string}>
     */
    public function checks(Application $application): array
    {
        return $this->platform(function () use ($application): array {
            $checks = [];
            $add = function (string $key, bool $ok, string $message, bool $required = true) use (&$checks): void {
                $checks[$key] = ['ok' => $ok, 'required' => $required, 'message' => $message];
            };

            $add('email_verified', $application->email_verified_at !== null, 'Email address confirmed');
            $add('cipc_format', Identifiers::cipc($application->registration_number), 'Company registration number looks like YYYY/NNNNNN/NN');
            $add('sdp_format', Identifiers::qcto($application->sdp_number), 'QCTO accreditation number looks like 07-QCTO/SDP…');

            $expires = $application->accreditation_expires_on;
            $add('accreditation_current', $expires !== null && $expires->isFuture() && $expires->lte(now()->addYears(5)->addMonth()),
                'Accreditation has not expired (and is within the 5-year validity)');

            if ($application->vat_number !== null) {
                $add('vat_format', Identifiers::vat($application->vat_number), 'VAT number has 10 digits starting with 4');
            }

            $add('address', $application->address_line1 !== null && $application->city !== null && $application->province !== null, 'Centre address given');
            $add('code_available', $this->codeProblem($application) === null, $this->codeProblem($application) ?? 'Web address is available');

            $reg = $application->registration_number;
            $sdp = $application->sdp_number;
            $duplicate = ($reg !== null && Tenant::query()->where('registration_number', $reg)->exists())
                || (($reg !== null || $sdp !== null) && Application::query()->whereKeyNot($application->id)->whereIn('status', ['submitted', 'info_requested', 'approved'])
                    ->where(function ($q) use ($reg, $sdp): void {
                        if ($reg !== null) {
                            $q->orWhere('registration_number', $reg);
                        }
                        if ($sdp !== null) {
                            $q->orWhere('sdp_number', $sdp);
                        }
                    })->exists());
            $add('not_duplicate', ! $duplicate, 'No other provider or application uses this company or accreditation number');

            $files = collect($this->files($application));
            $letter = $files->firstWhere('kind', 'accreditation_letter');
            $add('accreditation_letter', $letter !== null && $letter['status'] === 'ready', 'QCTO accreditation letter uploaded and checked');
            $add('consent', $application->consented_at !== null, 'Terms and POPIA consent accepted');

            // Signals for the reviewer, not blockers.
            $emailDomain = substr((string) strrchr($application->email, '@'), 1);
            $siteDomain = $application->website ? preg_replace('/^www\./', '', (string) parse_url($application->website, PHP_URL_HOST)) : null;
            if ($siteDomain) {
                $add('email_matches_website', $emailDomain === $siteDomain, 'Contact email uses the website\'s domain', false);
            }

            return $checks;
        });
    }

    public function consent(Application $application): Application
    {
        $this->platform(fn () => $application->forceFill(['consented_at' => now(), 'consent_version' => self::CONSENT_VERSION])->save());

        return $application;
    }

    public function submit(Application $application): Application
    {
        if (! $application->editable()) {
            throw ValidationException::withMessages(['status' => 'This application has already been submitted.']);
        }

        $checks = $this->checks($application);
        $failed = array_filter($checks, fn (array $c): bool => $c['required'] && ! $c['ok']);

        if ($failed !== []) {
            throw ValidationException::withMessages(array_map(fn (array $c): array => [$c['message']], $failed));
        }

        $resubmission = $application->status === 'info_requested';
        $this->platform(fn () => $application->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'auto_checks' => $checks])->save());
        $this->audit->record($resubmission ? 'application.resubmitted' : 'application.submitted', $application, meta: ['reference' => $application->reference]);

        Notification::route('mail', $application->email)->notify(new ApplicationReceived($application));
        $this->notifyReviewers($application);

        return $application;
    }

    /** @param  array<string, bool>  $checklist */
    public function review(Application $application, array $checklist, ?string $notes, User $reviewer): Application
    {
        $current = $application->checklist ?? [];
        foreach (self::CHECKLIST as $key => $_) {
            if (array_key_exists($key, $checklist)) {
                $current[$key] = (bool) $checklist[$key];
            }
        }

        $application->forceFill(['checklist' => $current, 'review_notes' => $notes ?? $application->review_notes, 'reviewed_by' => $reviewer->id])->save();

        return $application;
    }

    public function requestInfo(Application $application, string $message, User $reviewer): Application
    {
        $this->assertStatus($application, ['submitted']);
        $application->forceFill(['status' => 'info_requested', 'decision_reason' => $message, 'reviewed_by' => $reviewer->id])->save();
        $this->audit->record('application.info_requested', $application, meta: ['message' => $message]);
        Notification::route('mail', $application->email)->notify(new ApplicationNeedsInfo($application, $message));
        $this->toPhone($application, "{$application->reference}: we need a little more before we can approve your application. Details are in your email.");

        return $application;
    }

    public function decline(Application $application, string $reason, User $reviewer): Application
    {
        $this->assertStatus($application, ['submitted', 'info_requested']);
        $application->forceFill(['status' => 'declined', 'decision_reason' => $reason, 'reviewed_by' => $reviewer->id, 'decided_at' => now()])->save();
        $this->audit->record('application.declined', $application, meta: ['reason' => $reason]);
        Notification::route('mail', $application->email)->notify(new ApplicationDecided($application, false));
        $this->toPhone($application, "{$application->reference}: your application was not approved. The reason is in your email.");

        return $application;
    }

    /**
     * Approve: the provider goes live with its subdomain, first centre, plan trial
     * and billing details, and the contact is invited as owner.
     */
    public function approve(Application $application, User $reviewer): Tenant
    {
        $this->assertStatus($application, ['submitted']);

        $missing = array_keys(array_filter(self::CHECKLIST, fn ($_, string $key): bool => ! ($application->checklist[$key] ?? false), ARRAY_FILTER_USE_BOTH));
        if ($missing !== []) {
            throw ValidationException::withMessages(['checklist' => 'Tick every review check first: '.implode(', ', array_map(fn ($k) => self::CHECKLIST[$k], $missing)).'.']);
        }

        $failed = array_filter($this->checks($application), fn (array $c): bool => $c['required'] && ! $c['ok']);
        if ($failed !== []) {
            throw ValidationException::withMessages(['checks' => 'These checks no longer pass: '.implode('; ', array_column($failed, 'message')).'.']);
        }

        $tenant = DB::transaction(function () use ($application, $reviewer): Tenant {
            $package = $application->package_id ? Package::query()->find($application->package_id) : null;
            $tenants = app(TenantService::class);
            $tenant = $tenants->register([
                'code' => (string) $application->preferred_code,
                'legal_name' => $application->legal_name,
                'trading_name' => $application->trading_name,
                'registration_number' => $application->registration_number,
                'package_code' => $package?->code,
            ], createdBy: $reviewer->id);
            $tenant = $tenants->approve($tenant);

            $this->context->runAs($tenant, function () use ($tenant, $application, $package, $reviewer): void {
                Centre::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $application->centre_name ?: ($application->city ? $application->city.' centre' : 'Main centre'),
                    'accreditation_number' => $application->sdp_number,
                    'accreditation_expires_on' => $application->accreditation_expires_on,
                    'accreditation_type' => str_contains((string) $application->sdp_number, '/AC') ? 'ac' : 'sdp',
                    'scope' => $application->scope,
                    'address_line1' => $application->address_line1,
                    'city' => $application->city,
                    'province' => $application->province,
                    'postal_code' => $application->postal_code,
                ]);

                $profile = array_filter([
                    'legal_name' => $application->legal_name,
                    'vat_number' => $application->vat_number,
                    'registration_number' => $application->registration_number,
                    'address_line1' => $application->address_line1,
                    'city' => $application->city,
                    'province' => $application->province,
                    'postal_code' => $application->postal_code,
                    'email' => $application->email,
                ], fn ($v) => $v !== null);

                $subscriptions = app(SubscriptionService::class);
                if ($package !== null) {
                    $subscriptions->start($tenant, $package, $application->interval, $profile);
                } else {
                    $subscriptions->saveProfile($tenant, $profile);
                }

                app(InvitationService::class)->invite($reviewer, [
                    'email' => $application->email,
                    'phone' => $application->phone,
                    'first_name' => $application->first_name,
                    'last_name' => $application->last_name,
                    'role' => 'tp-owner',
                ]);
            });

            $application->forceFill(['status' => 'approved', 'tenant_id' => $tenant->id, 'reviewed_by' => $reviewer->id, 'decided_at' => now()])->save();

            return $tenant;
        });

        $this->audit->record('application.approved', $application, meta: ['tenant' => $tenant->code]);
        Notification::route('mail', $application->email)->notify(new ApplicationDecided($application, true, $tenant));
        $this->toPhone($application, "{$application->reference}: {$application->legal_name} is approved. Check your email for your invitation to {$tenant->code}.".config('platform.base_domain').'.');

        return $tenant;
    }

    /** Why the preferred web address can't be used, or null when it can. */
    public function codeProblem(Application $application): ?string
    {
        $code = (string) $application->preferred_code;
        if ($code === '') {
            return 'Choose a web address';
        }

        try {
            $code = app(TenantService::class)->validCode($code);
        } catch (TenantError $e) {
            return $e->getMessage();
        }

        $host = $code.'.'.config('platform.base_domain');
        $taken = $this->platform(fn () => Tenant::query()->where('code', $code)->exists()
            || TenantDomain::query()->where('hostname', $host)->exists()
            || Application::query()->whereKeyNot($application->id)->where('preferred_code', $code)->whereIn('status', ['submitted', 'info_requested'])->exists());

        return $taken ? 'That web address is already taken' : null;
    }

    /** @return array<string, mixed> */
    public function present(Application $application, bool $forReviewer = false): array
    {
        return [
            'id' => $application->id,
            'reference' => $application->reference,
            'status' => $application->status,
            'email_verified' => $application->email_verified_at !== null,
            ...$application->only([
                'first_name', 'last_name', 'email', 'phone', 'legal_name', 'trading_name', 'registration_number', 'vat_number',
                'website', 'sdp_number', 'centre_name', 'address_line1', 'city', 'province', 'postal_code', 'preferred_code',
                'package_id', 'interval',
            ]),
            'accreditation_expires_on' => $application->accreditation_expires_on?->toDateString(),
            'scope' => $application->scope ?? [],
            'consented' => $application->consented_at !== null,
            'message' => $application->status === 'info_requested' || $application->status === 'declined' ? $application->decision_reason : null,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'files' => $this->files($application),
            'checks' => $this->checks($application),
            'base_domain' => config('platform.base_domain'),
            ...($forReviewer ? [
                'checklist' => array_map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'done' => (bool) ($application->checklist[$key] ?? false)], self::CHECKLIST, array_keys(self::CHECKLIST)),
                'review_notes' => $application->review_notes,
                'decided_at' => $application->decided_at?->toIso8601String(),
                'tenant_id' => $application->tenant_id,
                'ip' => $application->ip,
                'created_at' => $application->created_at->toIso8601String(),
            ] : []),
        ];
    }

    /** A short copy of a decision by WhatsApp or SMS, when the applicant gave a mobile number. */
    private function toPhone(Application $application, string $text): void
    {
        $to = $application->phone !== null ? PhoneNumber::normalise($application->phone) : null;
        if ($to === null) {
            return;
        }

        try {
            $this->platform(fn () => app(MessageService::class)->notify($to, $text, 'application'));
        } catch (\Throwable $e) {
            report($e); // email already went; a phone copy must never block a decision
        }
    }

    private function notifyReviewers(Application $application): void
    {
        $emails = $this->platform(fn () => User::query()
            ->whereIn('id', PlatformRoleAssignment::query()->where('role', 'super-admin')->pluck('user_id'))
            ->whereNotNull('email')->pluck('email')->all());

        foreach ($emails as $email) {
            Notification::route('mail', $email)->notify(new ApplicationToReview($application));
        }
    }

    /** @param  list<string>  $allowed */
    private function assertStatus(Application $application, array $allowed): void
    {
        if (! in_array($application->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'This application is '.str_replace('_', ' ', $application->status).'.']);
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function platform(\Closure $callback): mixed
    {
        return $this->context->isPlatform() ? $callback() : $this->context->runAsPlatform($callback);
    }
}
