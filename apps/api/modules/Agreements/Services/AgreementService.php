<?php

declare(strict_types=1);

namespace Modules\Agreements\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\AgreementSettings;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Models\Template;
use Modules\Agreements\Signing\LawtrustSignatures;
use Modules\Agreements\Signing\SignatureProvider;
use Modules\Agreements\Signing\SimpleSignatures;
use Modules\Agreements\Support\AgreementText;
use Modules\Audit\AuditLogger;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\PhoneNumber;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Prepares, sends, follows up and completes agreements. Runs in the provider's context. */
final class AgreementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly Invitations $invitations,
        private readonly AgreementDocuments $documents,
    ) {}

    public function provider(string $level): SignatureProvider
    {
        return app($level === 'advanced' ? LawtrustSignatures::class : SimpleSignatures::class);
    }

    /** @param  array<string, mixed>  $input */
    public function send(array $input, User $by): Agreement
    {
        $data = Validator::make($input, [
            'template_id' => ['required', 'ulid'],
            'title' => ['nullable', 'string', 'max:200'],
            'employer_id' => ['nullable', 'ulid'],
            'site_id' => ['nullable', 'ulid'],
            'mentor_id' => ['nullable', 'ulid'],
            'qualification_id' => ['nullable', 'ulid'],
            'learner_user_id' => ['nullable', 'ulid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'signers' => ['required', 'array', 'min:2', 'max:3'],
            'signers.*.role' => ['required', 'distinct', Rule::in(Template::ROLES)],
            'signers.*.name' => ['required', 'string', 'min:2', 'max:150'],
            'signers.*.email' => ['nullable', 'email:rfc', 'max:200'],
            'signers.*.phone' => ['nullable', 'string', 'max:20'],
            'signers.*.user_id' => ['nullable', 'ulid'],
        ])->validate();

        $id = fn (string $key): ?string => isset($data[$key]) ? (string) $data[$key] : null;
        $template = Template::query()->where('status', 'published')->whereKey($id('template_id'))->first()
            ?? throw ValidationException::withMessages(['template_id' => 'Choose a published agreement.']);
        $employer = $id('employer_id') !== null ? Employer::query()->whereKey($id('employer_id'))->firstOrFail() : null;
        $site = $id('site_id') !== null ? (EmployerSite::query()->where('employer_id', $employer?->id)->whereKey($id('site_id'))->first()
            ?? throw ValidationException::withMessages(['site_id' => 'Choose one of the employer\'s workplaces.'])) : null;
        $mentor = $id('mentor_id') !== null ? (Mentor::query()->where('employer_id', $employer?->id)->whereKey($id('mentor_id'))->first()
            ?? throw ValidationException::withMessages(['mentor_id' => 'Choose one of the employer\'s mentors.'])) : null;
        $qualification = $id('qualification_id') !== null ? Qualification::query()->whereKey($id('qualification_id'))->firstOrFail() : null;
        $learner = $id('learner_user_id') !== null && UserTenant::query()->where('user_id', $id('learner_user_id'))->exists()
            ? User::query()->whereKey($id('learner_user_id'))->first() : null;
        if ($site !== null && $qualification !== null && ! $site->approvedFor($qualification->id)) {
            throw ValidationException::withMessages(['site_id' => 'This workplace is not approved for that qualification, or its approval has ended.']);
        }

        /** @var list<array{role: string, name: string, email?: string|null, phone?: string|null, user_id?: string|null}> $given */
        $given = $data['signers'];
        $roles = collect($given)->pluck('role')->sort()->values()->all();
        $needed = collect($template->parties)->sort()->values()->all();
        if ($roles !== $needed) {
            throw ValidationException::withMessages(['signers' => 'This agreement is signed by: '.implode(', ', array_map(fn ($r) => AgreementDocuments::ROLES[$r], $template->parties)).'.']);
        }
        $level = $template->advanced_signature ? 'advanced' : 'simple';
        $provider = $this->provider($level);
        if (! $provider->available()) {
            throw ValidationException::withMessages(['template_id' => 'This agreement needs an advanced e-signature, and LAWtrust is not connected yet. Ask the platform team to connect it, or switch the agreement to standard signatures.']);
        }
        $signers = [];
        foreach ($given as $i => $s) {
            $phone = isset($s['phone']) ? (PhoneNumber::normalise((string) $s['phone']) ?? throw ValidationException::withMessages(["signers.{$i}.phone" => 'That phone number is not valid.'])) : null;
            if ($phone === null && empty($s['email'])) {
                throw ValidationException::withMessages(["signers.{$i}.phone" => 'Give a phone number or an email, so we can send the link and PIN.']);
            }
            if ($level === 'advanced' && empty($s['email'])) {
                throw ValidationException::withMessages(["signers.{$i}.email" => 'Advanced signatures are sent by email: add an email address.']);
            }
            $signers[] = ['role' => $s['role'], 'name' => trim((string) $s['name']), 'email' => $s['email'] ?? null, 'phone' => $phone,
                'user_id' => $s['user_id'] ?? null, 'sort' => array_search($s['role'], $template->parties, true)];
        }

        $tenantId = $this->context->tenantId();
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $learnerName = collect($signers)->firstWhere('role', 'learner')['name'] ?? $learner?->fullName();
        $values = array_filter([
            'provider.name' => $tenant->trading_name,
            'provider.legal_name' => $tenant->legal_name,
            'provider.registration_number' => $tenant->registration_number,
            'employer.legal_name' => $employer?->legal_name,
            'employer.registration_number' => $employer?->registration_number,
            'employer.sdl_number' => $employer?->sdl_number,
            'employer.contact_name' => $employer?->contact_name,
            'site.name' => $site?->name,
            'site.address' => $site ? implode(', ', array_filter([$site->address_line1, $site->city, $site->province, $site->postal_code])) : null,
            'site.approval_number' => $site?->approval_number,
            'mentor.name' => $mentor?->name,
            'learner.name' => $learnerName,
            'qualification.title' => $qualification?->title,
            'qualification.saqa_id' => $qualification?->saqa_id,
            'start_date' => isset($data['start_date']) ? now()->parse($data['start_date'])->format('j F Y') : null,
            'end_date' => isset($data['end_date']) ? now()->parse($data['end_date'])->format('j F Y') : null,
            'today' => now()->timezone('Africa/Johannesburg')->format('j F Y'),
        ], fn ($v) => filled($v));
        $missing = array_diff(AgreementText::fieldsIn($template->body), array_keys($values));
        if ($missing !== []) {
            throw ValidationException::withMessages(['template_id' => 'Still needed for this agreement: '.implode(', ', array_map(fn ($f) => AgreementText::FIELDS[$f], $missing)).'.']);
        }
        $body = AgreementText::merge($template->body, $values);

        $agreement = DB::transaction(function () use ($template, $data, $body, $values, $level, $employer, $site, $qualification, $learner, $signers, $by): Agreement {
            $agreement = Agreement::query()->create([
                'template_id' => $template->id, 'kind' => $template->kind,
                'title' => $data['title'] ?? $template->name.($values['learner.name'] ?? null ? ' — '.$values['learner.name'] : ''),
                'body' => $body, 'body_sha256' => hash('sha256', $body), 'merge' => $values, 'signature_level' => $level,
                'employer_id' => $employer?->id, 'site_id' => $site?->id, 'qualification_id' => $qualification?->id, 'learner_user_id' => $learner?->id,
                'verify_code' => strtolower(Str::random(10)), 'sent_at' => now(), 'expires_at' => now()->addDays(AgreementSettings::current()->valid_days),
                'created_by' => $by->id,
            ]);
            $agreement->forceFill(['status' => 'sent'])->save();
            foreach ($signers as $s) {
                Signer::query()->create([...$s, 'agreement_id' => $agreement->id, 'token_hash' => hash('sha256', Str::random(48))]);
            }

            return $agreement;
        });
        $provider->start($agreement);
        $this->audit->record('agreement.sent', $agreement, [], ['title' => $agreement->title, 'level' => $level, 'sha256' => $agreement->body_sha256]);

        return $agreement;
    }

    public function resend(Signer $signer): void
    {
        $agreement = $signer->agreement()->firstOrFail();
        if ($agreement->status !== 'sent' || $signer->status !== 'pending' || $agreement->signature_level !== 'simple') {
            throw ValidationException::withMessages(['signer' => 'Only a waiting signature can be sent again.']);
        }
        $this->invitations->send($signer, $agreement);
        $this->audit->record('agreement.resent', $agreement, [], ['signer' => $signer->role]);
    }

    public function cancel(Agreement $agreement, string $reason): void
    {
        if (! in_array($agreement->status, ['sent', 'draft'], true)) {
            throw ValidationException::withMessages(['status' => 'Only an agreement waiting for signatures can be cancelled.']);
        }
        $agreement->forceFill(['status' => 'cancelled'])->save();
        $this->audit->record('agreement.cancelled', $agreement, [], ['reason' => $reason]);
    }

    /** Everyone has signed: seal the PDF (simple) or keep the provider's (advanced), then tell them. */
    public function complete(Agreement $agreement): void
    {
        $agreement->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        if ($agreement->signature_level === 'simple') {
            $this->documents->seal($agreement);
        } else {
            Signer::query()->where('agreement_id', $agreement->id)->where('status', 'pending')
                ->update(['status' => 'signed', 'signed_at' => now(), 'evidence' => json_encode(['channel' => 'LAWtrust SigningHub'])]);
        }
        $this->audit->record('agreement.completed', $agreement, [], ['sha256' => $agreement->sealed_sha256]);
        $this->invitations->completed($agreement);
    }

    /** Check an advanced agreement's progress with its provider. */
    public function sync(Agreement $agreement): void
    {
        if ($agreement->status === 'sent' && $this->provider($agreement->signature_level)->sync($agreement)) {
            $this->complete($agreement->refresh());
        }
    }
}
