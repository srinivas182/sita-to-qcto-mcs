<?php

declare(strict_types=1);

namespace Modules\Employers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerMember;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Employers\Services\EmployerService;
use Modules\Employers\Support\Setas;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;

/** Host employers, workplace sites, mentors and the people who act for the employer. */
final class EmployerController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('employers.view');
        $query = Employer::query()->orderBy('legal_name');
        if (($q = trim((string) $request->query('q', ''))) !== '') {
            $query->where(fn ($w) => $w->where('legal_name', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('trading_name', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('registration_number', $q));
        }
        $employers = $query->get();
        $sites = EmployerSite::query()->whereIn('employer_id', $employers->pluck('id'))->get()->groupBy('employer_id');
        $mentors = Mentor::query()->where('active', true)->whereIn('employer_id', $employers->pluck('id'))->get()->groupBy('employer_id');

        return response()->json([
            'data' => $employers->map(function (Employer $e) use ($sites, $mentors): array {
                $own = $sites->get($e->id) ?? collect();
                $states = $own->map(fn (EmployerSite $s) => $s->approvalState());

                return $this->summary($e) + [
                    'sites' => $own->count(),
                    'mentors' => ($mentors->get($e->id) ?? collect())->count(),
                    // The worst approval state across the employer's sites.
                    'approval' => collect(['expired', 'expiring', 'not_approved', 'approved'])->first(fn (string $s) => $states->contains($s)) ?? 'not_approved',
                ];
            })->values()->all(),
            'setas' => Setas::ALL,
            'bbbee' => Setas::BBBEE,
        ]);
    }

    public function show(string $employer): JsonResponse
    {
        Gate::authorize('employers.view');
        $e = Employer::query()->findOrFail($employer);

        return response()->json(['data' => $this->detail($e), 'setas' => Setas::ALL, 'bbbee' => Setas::BBBEE]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('employers.manage');
        $e = $this->service()->saveEmployer($request->all(), null);

        return response()->json(['data' => $this->detail($e)], 201);
    }

    public function update(Request $request, string $employer): JsonResponse
    {
        Gate::authorize('employers.manage');
        $e = $this->service()->saveEmployer($request->all(), Employer::query()->findOrFail($employer));

        return response()->json(['data' => $this->detail($e)]);
    }

    public function storeSite(Request $request, string $employer): JsonResponse
    {
        Gate::authorize('employers.manage');
        $e = Employer::query()->findOrFail($employer);
        $this->service()->saveSite($e, $request->all(), null);

        return response()->json(['data' => $this->detail($e)], 201);
    }

    public function updateSite(Request $request, string $employer, string $site): JsonResponse
    {
        Gate::authorize('employers.manage');
        $e = Employer::query()->findOrFail($employer);
        $this->service()->saveSite($e, $request->all(), EmployerSite::query()->where('employer_id', $e->id)->findOrFail($site));

        return response()->json(['data' => $this->detail($e)]);
    }

    public function storeMentor(Request $request, string $employer): JsonResponse
    {
        Gate::authorize('employers.manage');
        $e = Employer::query()->findOrFail($employer);
        $this->service()->saveMentor($e, $request->all(), null);

        return response()->json(['data' => $this->detail($e)], 201);
    }

    public function updateMentor(Request $request, string $employer, string $mentor): JsonResponse
    {
        Gate::authorize('employers.manage');
        $e = Employer::query()->findOrFail($employer);
        $this->service()->saveMentor($e, $request->all(), Mentor::query()->where('employer_id', $e->id)->findOrFail($mentor));

        return response()->json(['data' => $this->detail($e)]);
    }

    public function link(Request $request, string $employer): JsonResponse
    {
        Gate::authorize('employers.manage');
        $data = $request->validate(['user_id' => ['required', 'ulid']]);
        $e = Employer::query()->findOrFail($employer);
        $this->service()->link($e, $data['user_id']);

        return response()->json(['data' => $this->detail($e)], 201);
    }

    public function unlink(string $employer, string $user): JsonResponse
    {
        Gate::authorize('employers.manage');
        EmployerMember::query()->where('employer_id', $employer)->where('user_id', $user)->delete();

        return response()->json(['data' => $this->detail(Employer::query()->findOrFail($employer))]);
    }

    /** @return array<string, mixed> */
    private function summary(Employer $e): array
    {
        return $e->only(['id', 'legal_name', 'trading_name', 'registration_number', 'sdl_number', 'seta', 'bbbee_level', 'sector',
            'contact_name', 'contact_email', 'contact_phone', 'status', 'notes']);
    }

    /** @return array<string, mixed> */
    private function detail(Employer $e): array
    {
        $sites = $e->sites()->get();
        $titles = Qualification::query()->whereIn('id', $sites->pluck('approved_qualification_ids')->flatten()->filter()->unique()->all())->pluck('title', 'id');
        $memberIds = EmployerMember::query()->where('employer_id', $e->id)->pluck('user_id');

        return $this->summary($e) + [
            'sites' => $sites->map(fn (EmployerSite $s) => $s->only(['id', 'name', 'address_line1', 'city', 'province', 'postal_code',
                'approval_number', 'approval_body', 'approval_file_id', 'mentor_ratio', 'status']) + [
                    'latitude' => $s->latitude !== null ? (float) $s->latitude : null,
                    'longitude' => $s->longitude !== null ? (float) $s->longitude : null,
                    'approved_on' => $s->approved_on?->toDateString(),
                    'approval_expires_on' => $s->approval_expires_on?->toDateString(),
                    'approval_state' => $s->approvalState(),
                    'qualifications' => collect($s->approved_qualification_ids ?? [])->map(fn (string $id) => ['id' => $id, 'title' => $titles->get($id)])->values()->all(),
                    'ratio_overrides' => (object) ($s->ratio_overrides ?? []),
                ])->values()->all(),
            'mentors' => $e->mentors()->get()->map(fn (Mentor $m) => $m->only(['id', 'user_id', 'name', 'email', 'phone', 'designation',
                'years_experience', 'highest_qualification', 'qualification_nqf_level', 'site_ids', 'active']))->values()->all(),
            'members' => User::query()->whereIn('id', $memberIds)->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->fullName(), 'email' => $u->email])->values()->all(),
            'capacity' => $this->service()->capacity($e),
            'workplace_criteria' => $this->service()->workplaceCriteria($e),
        ];
    }

    private function service(): EmployerService
    {
        return app(EmployerService::class);
    }
}
