<?php

declare(strict_types=1);

namespace Modules\People\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Enrolment\Services\QctoEnrolmentList;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\People\Models\StaffCredential;
use Modules\People\Services\CredentialService;
use Modules\People\Services\PersonImport;
use Modules\People\Services\ProfileService;
use Modules\Qualifications\Models\Qualification;

/** Profiles, the staff credential register and bulk import, inside a provider. */
final class PeopleController
{
    // ── Profiles ────────────────────────────────────────────────

    public function profile(Request $request, string $person): JsonResponse
    {
        Gate::authorize('tenant.users.view');

        $p = $this->member($person);

        return response()->json(['data' => $this->profiles()->present($p, Gate::allows('people.manage')), 'qcto' => app(QctoEnrolmentList::class)->forUser($p)]);
    }

    public function updateProfile(Request $request, string $person): JsonResponse
    {
        Gate::authorize('people.manage');
        $model = $this->member($person);
        $result = $this->profiles()->update($model, $request->all(), $this->actor($request), true);

        return response()->json(['data' => $this->profiles()->present($model->refresh(), true), 'qcto' => app(QctoEnrolmentList::class)->forUser($model)] + $result);
    }

    public function myProfile(Request $request): JsonResponse
    {
        $me = $this->actor($request);

        return response()->json(['data' => $this->profiles()->present($me, true), 'qcto' => app(QctoEnrolmentList::class)->forUser($me)]);
    }

    public function updateMyProfile(Request $request): JsonResponse
    {
        $me = $this->actor($request);
        $result = $this->profiles()->update($me, $request->except(['first_name', 'last_name']), $me, false);

        return response()->json(['data' => $this->profiles()->present($me->refresh(), true), 'qcto' => app(QctoEnrolmentList::class)->forUser($me)] + $result);
    }

    // ── Credentials ─────────────────────────────────────────────

    /** The register: everyone's registrations, filterable, with what needs attention first. */
    public function register(Request $request): JsonResponse
    {
        Gate::authorize('credentials.view');
        $query = StaffCredential::query();
        if (($role = (string) $request->query('role', '')) !== '') {
            $query->where('role', $role);
        }
        if (($status = (string) $request->query('status', '')) !== '') {
            $query->where('status', $status);
        }
        $rows = $query->get();
        $people = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        $titles = Qualification::query()->whereIn('id', $rows->pluck('qualification_ids')->flatten()->unique()->all())->pluck('title', 'id');
        $order = ['expired' => 0, 'pending' => 1, 'expiring' => 2, 'rejected' => 3, 'ok' => 4];

        return response()->json([
            'data' => $rows->sortBy(fn (StaffCredential $c) => [$order[$c->state()] ?? 9, $c->expires_on !== null ? $c->expires_on->timestamp : PHP_INT_MAX])->values()
                ->map(fn (StaffCredential $c) => $this->presentCredential($c, $titles) + ['person' => $people->get($c->user_id)?->fullName()])->all(),
            'counts' => collect($rows)->countBy(fn (StaffCredential $c) => $c->state())->all(),
            'roles' => StaffCredential::ROLES,
            'bodies' => StaffCredential::BODIES,
        ]);
    }

    public function credentials(string $person): JsonResponse
    {
        Gate::authorize('credentials.view');

        return response()->json(['data' => $this->listFor($this->member($person)->id)]);
    }

    public function myCredentials(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->listFor($this->actor($request)->id), 'roles' => StaffCredential::ROLES, 'bodies' => StaffCredential::BODIES]);
    }

    public function addCredential(Request $request, string $person): JsonResponse
    {
        Gate::authorize('credentials.manage');
        $c = $this->credentialService()->save($this->member($person), $request->all(), null, $this->actor($request));

        return response()->json(['data' => $this->presentCredential($c)], 201);
    }

    /** Self-service: staff add their own registrations; someone else verifies them. */
    public function addMyCredential(Request $request): JsonResponse
    {
        $me = $this->actor($request);
        $c = $this->credentialService()->save($me, $request->all(), null, $me);

        return response()->json(['data' => $this->presentCredential($c)], 201);
    }

    public function updateCredential(Request $request, string $credential): JsonResponse
    {
        $c = StaffCredential::query()->findOrFail($credential);
        $actor = $this->actor($request);
        if ($c->user_id !== $actor->id) {
            Gate::authorize('credentials.manage');
        }
        $c = $this->credentialService()->save(User::query()->findOrFail($c->user_id), $request->all(), $c, $actor);

        return response()->json(['data' => $this->presentCredential($c)]);
    }

    public function verifyCredential(Request $request, string $credential): JsonResponse
    {
        Gate::authorize('credentials.manage');
        $c = $this->credentialService()->verify(StaffCredential::query()->findOrFail($credential), $this->actor($request));

        return response()->json(['data' => $this->presentCredential($c)]);
    }

    public function rejectCredential(Request $request, string $credential): JsonResponse
    {
        Gate::authorize('credentials.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $c = $this->credentialService()->reject(StaffCredential::query()->findOrFail($credential), $data['reason'], $this->actor($request));

        return response()->json(['data' => $this->presentCredential($c)]);
    }

    /** May this person facilitate / assess / moderate this qualification (on a date)? */
    public function eligibility(Request $request, string $person): JsonResponse
    {
        Gate::authorize('credentials.view');
        $data = $request->validate([
            'role' => ['required', 'in:'.implode(',', array_keys(StaffCredential::ROLES))],
            'qualification_id' => ['required', 'ulid'],
            'on' => ['nullable', 'date'],
        ]);
        $result = $this->credentialService()->eligibility($this->member($person)->id, $data['role'], $data['qualification_id'],
            isset($data['on']) ? CarbonImmutable::parse($data['on']) : null);

        return response()->json(['data' => $result]);
    }

    // ── Import ──────────────────────────────────────────────────

    public function import(Request $request): JsonResponse
    {
        Gate::authorize('people.manage');
        Gate::authorize('tenant.users.invite');
        $data = $request->validate(['csv' => ['required', 'string', 'max:500000'], 'send' => ['sometimes', 'boolean']]);

        return response()->json(app(PersonImport::class)->run($data['csv'], $this->actor($request), (bool) ($data['send'] ?? false)));
    }

    // ── Helpers ─────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function listFor(string $userId): array
    {
        $rows = StaffCredential::query()->where('user_id', $userId)->orderBy('role')->get();
        $titles = Qualification::query()->whereIn('id', $rows->pluck('qualification_ids')->flatten()->unique()->all())->pluck('title', 'id');

        return array_values($rows->map(fn (StaffCredential $c) => $this->presentCredential($c, $titles))->all());
    }

    /**
     * @param  Collection<string, string>|null  $titles
     * @return array<string, mixed>
     */
    private function presentCredential(StaffCredential $c, $titles = null): array
    {
        $titles ??= Qualification::query()->whereIn('id', $c->qualification_ids)->pluck('title', 'id');

        return [
            'id' => $c->id, 'user_id' => $c->user_id, 'role' => $c->role, 'role_label' => StaffCredential::ROLES[$c->role] ?? $c->role,
            'body' => $c->body, 'registration_number' => $c->registration_number,
            'qualifications' => collect($c->qualification_ids)->map(fn (string $id) => ['id' => $id, 'title' => $titles->get($id)])->values()->all(),
            'starts_on' => $c->starts_on?->toDateString(), 'expires_on' => $c->expires_on?->toDateString(),
            'file_id' => $c->file_id, 'status' => $c->status, 'state' => $c->state(), 'verified_at' => $c->verified_at?->toIso8601String(),
            'notes' => $c->notes,
        ];
    }

    /** A person who belongs to this provider (memberships are under row-level security). */
    private function member(string $id): User
    {
        abort_unless(UserTenant::query()->where('user_id', $id)->exists(), 404);

        return User::query()->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function profiles(): ProfileService
    {
        return app(ProfileService::class);
    }

    private function credentialService(): CredentialService
    {
        return app(CredentialService::class);
    }
}
