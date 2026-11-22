<?php

declare(strict_types=1);

namespace Modules\Employers\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerMember;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Employers\Support\Setas;
use Modules\Files\Models\File;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\PhoneNumber;
use Modules\Onboarding\Support\Identifiers;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;

/** Host employers, their approved workplaces, mentors and capacity. Runs in the provider's context. */
final class EmployerService
{
    private const PROVINCES = ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'];

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /** @param  array<string, mixed>  $input */
    public function saveEmployer(array $input, ?Employer $existing): Employer
    {
        $req = $existing ? 'sometimes' : 'required';
        $data = Validator::make($input, [
            'legal_name' => [$req, 'string', 'min:2', 'max:200'],
            'trading_name' => ['nullable', 'string', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:20',
                Rule::unique('employers', 'registration_number')->where('tenant_id', $this->context->tenantId())->ignore($existing?->id)],
            'sdl_number' => ['nullable', 'string', 'max:10'],
            'seta' => ['nullable', Rule::in(Setas::ALL)],
            'bbbee_level' => ['nullable', Rule::in(Setas::BBBEE)],
            'sector' => ['nullable', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email:rfc', 'max:200'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['registration_number.unique' => 'You already have an employer with this registration number.'])->validate();

        if (isset($data['registration_number'])) {
            $data['registration_number'] = Identifiers::normalise($data['registration_number']);
            if (! Identifiers::cipc($data['registration_number'])) {
                throw ValidationException::withMessages(['registration_number' => 'Use the CIPC format, e.g. 2015/123456/07.']);
            }
        }
        if (isset($data['sdl_number'])) {
            $data['sdl_number'] = strtoupper(str_replace(' ', '', (string) $data['sdl_number']));
            if (! Setas::sdl($data['sdl_number'])) {
                throw ValidationException::withMessages(['sdl_number' => 'The levy number is L followed by 9 digits, e.g. L123456789.']);
            }
        }
        if (isset($data['contact_phone'])) {
            $data['contact_phone'] = PhoneNumber::normalise((string) $data['contact_phone'])
                ?? throw ValidationException::withMessages(['contact_phone' => 'That phone number is not valid.']);
        }

        $employer = $existing ?? new Employer;
        $employer->fill($data)->save();
        $this->audit->record($existing ? 'employer.updated' : 'employer.added', $employer, [], ['name' => $employer->legal_name]);

        return $employer;
    }

    /** @param  array<string, mixed>  $input */
    public function saveSite(Employer $employer, array $input, ?EmployerSite $existing): EmployerSite
    {
        $req = $existing ? 'sometimes' : 'required';
        $data = Validator::make($input, [
            'name' => [$req, 'string', 'min:2', 'max:150', Rule::unique('employer_sites', 'name')->where('employer_id', $employer->id)->ignore($existing?->id)],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', Rule::in(self::PROVINCES)],
            'postal_code' => ['nullable', 'regex:/^\d{4}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-35,-22'],
            'longitude' => ['nullable', 'numeric', 'between:16,33'],
            'approval_number' => ['nullable', 'string', 'max:60'],
            'approval_body' => ['nullable', 'required_with:approval_number', Rule::in(Setas::ALL)],
            'approved_on' => ['nullable', 'date'],
            'approval_expires_on' => ['nullable', 'date', 'after_or_equal:approved_on'],
            'approved_qualification_ids' => ['nullable', 'array', 'max:100'],
            'approved_qualification_ids.*' => ['ulid', 'distinct'],
            'approval_file_id' => ['nullable', 'ulid'],
            'mentor_ratio' => ['sometimes', 'integer', 'between:1,50'],
            'ratio_overrides' => ['nullable', 'array'],
            'ratio_overrides.*' => ['integer', 'between:1,50'],
            'status' => ['sometimes', 'in:active,inactive'],
        ], [
            'postal_code.regex' => 'A South African postal code has 4 digits.',
            'latitude.between' => 'That location is outside South Africa.',
            'longitude.between' => 'That location is outside South Africa.',
            'approval_body.required_with' => 'Which SETA approved this workplace?',
        ])->validate();

        $qualifications = array_values(array_unique(array_merge($data['approved_qualification_ids'] ?? [], array_keys($data['ratio_overrides'] ?? []))));
        if ($qualifications !== [] && Qualification::query()->whereIn('id', $qualifications)->count() !== count($qualifications)) {
            throw ValidationException::withMessages(['approved_qualification_ids' => 'Choose qualifications from the catalogue.']);
        }
        if (isset($data['approval_file_id']) && ! File::query()->whereKey($data['approval_file_id'])->exists()) {
            throw ValidationException::withMessages(['approval_file_id' => 'Upload the approval letter first.']);
        }

        $site = $existing ?? new EmployerSite(['employer_id' => $employer->id]);
        $site->fill($data);
        if ($site->isDirty('approval_expires_on')) {
            $site->alerted_for = null; // renewed: reminders start again
        }
        $site->save();
        $this->audit->record($existing ? 'workplace.updated' : 'workplace.added', $site, [], ['employer' => $employer->legal_name, 'approval' => $site->approval_number]);

        return $site;
    }

    /** @param  array<string, mixed>  $input */
    public function saveMentor(Employer $employer, array $input, ?Mentor $existing): Mentor
    {
        $req = $existing ? 'sometimes' : 'required';
        $data = Validator::make($input, [
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'phone' => ['nullable', 'string', 'max:20'],
            'designation' => [$req, 'string', 'min:2', 'max:150'],
            'years_experience' => [$req, 'integer', 'between:0,60'],
            'highest_qualification' => ['nullable', 'string', 'max:200'],
            'qualification_nqf_level' => ['nullable', 'integer', 'between:1,10'],
            'site_ids' => [$req, 'array', 'min:1'],
            'site_ids.*' => ['ulid', 'distinct'],
            'user_id' => ['nullable', 'ulid'],
            'active' => ['sometimes', 'boolean'],
        ], ['site_ids.required' => 'Choose the workplace(s) where they mentor.'])->validate();

        if (isset($data['site_ids']) && EmployerSite::query()->where('employer_id', $employer->id)->whereIn('id', $data['site_ids'])->count() !== count($data['site_ids'])) {
            throw ValidationException::withMessages(['site_ids' => 'Choose this employer\'s own workplaces.']);
        }
        if (isset($data['user_id']) && ! UserTenant::query()->where('user_id', $data['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Invite them first, then link them here.']);
        }
        if (isset($data['phone'])) {
            $data['phone'] = PhoneNumber::normalise((string) $data['phone']) ?? throw ValidationException::withMessages(['phone' => 'That phone number is not valid.']);
        }

        $mentor = $existing ?? new Mentor(['employer_id' => $employer->id]);
        $mentor->fill($data)->save();
        $this->audit->record($existing ? 'mentor.updated' : 'mentor.added', $mentor, [], ['employer' => $employer->legal_name]);

        return $mentor;
    }

    public function link(Employer $employer, string $userId): EmployerMember
    {
        if (! UserTenant::query()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Invite them first, then link them to the employer.']);
        }
        $member = EmployerMember::query()->firstOrCreate(['employer_id' => $employer->id, 'user_id' => $userId]);
        $this->audit->record('employer.member_linked', $employer, [], ['user' => $userId]);

        return $member;
    }

    /**
     * Learners each workplace can take per approved qualification: active mentors at the
     * site × learners per mentor. "Placed" is filled in once cohorts place learners (3e).
     *
     * @return list<array{site_id: string, qualification_id: string, title: string, mentors: int, ratio: int, capacity: int}>
     */
    public function capacity(Employer $employer): array
    {
        $sites = $employer->sites()->get();
        $mentors = $employer->mentors()->where('active', true)->get();
        $titles = Qualification::query()->whereIn('id', $sites->pluck('approved_qualification_ids')->flatten()->filter()->unique()->all())->pluck('title', 'id');
        $rows = [];
        foreach ($sites as $site) {
            $count = $mentors->filter(fn (Mentor $m) => in_array($site->id, $m->site_ids, true))->count();
            foreach ($site->approved_qualification_ids ?? [] as $q) {
                $ratio = $site->ratioFor($q);
                $rows[] = ['site_id' => $site->id, 'qualification_id' => $q, 'title' => (string) $titles->get($q), 'mentors' => $count,
                    'ratio' => $ratio, 'capacity' => $site->approvedFor($q) ? $count * $ratio : 0];
            }
        }

        return $rows;
    }

    /**
     * The curriculum's own workplace requirements (work experience modules) for the
     * qualifications a site is approved for, shown beside the mentor matrix. We show
     * them rather than judge mentors against them: curricula word them differently.
     *
     * @return list<array{qualification: string, code: string, title: string, criteria: string}>
     */
    public function workplaceCriteria(Employer $employer): array
    {
        $ids = $employer->sites()->get()->pluck('approved_qualification_ids')->flatten()->filter()->unique()->all();
        $titles = Qualification::query()->whereIn('id', $ids)->pluck('title', 'id');

        return array_values(QualificationModule::query()->whereIn('qualification_id', $ids)->where('kind', 'WM')->whereNotNull('workplace_criteria')
            ->orderBy('sort')->get()->map(fn (QualificationModule $m): array => [
                'qualification' => (string) $titles->get($m->qualification_id), 'code' => $m->code, 'title' => $m->title, 'criteria' => (string) $m->workplace_criteria,
            ])->all());
    }
}
