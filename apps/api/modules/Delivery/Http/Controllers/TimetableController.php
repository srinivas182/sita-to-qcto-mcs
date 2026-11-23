<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Models\Room;
use Modules\Delivery\Services\TimetableService;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Models\Centre;

/** A cohort's timetable, rooms of a centre, and "my timetable" for learners and facilitators. */
final class TimetableController
{
    public function index(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = $this->cohort($request, $cohort, 'cohorts.view');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = ClassSession::query()->where('cohort_id', $c->id)->orderBy('starts_at');
        if (! empty($data['from'])) {
            $q->where('starts_at', '>=', CarbonImmutable::parse((string) $data['from'], TimetableService::TZ)->startOfDay()->utc());
        }
        if (! empty($data['to'])) {
            $q->where('starts_at', '<=', CarbonImmutable::parse((string) $data['to'], TimetableService::TZ)->endOfDay()->utc());
        }
        $sessions = $q->limit(500)->get();
        $staff = CohortStaff::query()->where('cohort_id', $c->id)->where('role', 'facilitator')->pluck('user_id');

        return response()->json([
            'data' => $this->present($sessions),
            'hours' => $this->timetable()->hours($c),
            'options' => [
                'modules' => QualificationModule::query()->where('qualification_id', $c->qualification_id)->whereIn('kind', ['KM', 'PM'])->orderBy('sort')
                    ->get(['id', 'code', 'title', 'kind'])->values()->all(),
                'rooms' => Room::query()->where('centre_id', $c->centre_id)->where('active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capacity'])->values()->all(),
                'facilitators' => User::query()->whereIn('id', $staff)->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->fullName()])->values()->all(),
            ],
            'cohort' => ['id' => $c->id, 'name' => $c->name, 'centre_id' => $c->centre_id, 'starts_on' => $c->intake_on->toDateString(), 'ends_on' => $c->planned_end_on->toDateString()],
        ]);
    }

    public function store(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $c = $this->cohort($request, $cohort, 'cohorts.manage');
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => $this->present(collect($this->timetable()->create($c, $request->all(), $by)))], 201);
    }

    public function update(Request $request, string $session): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $s = $this->session($request, $session);
        $scope = $request->validate(['scope' => ['sometimes', Rule::in(['one', 'following'])]])['scope'] ?? 'one';

        return response()->json(['data' => $this->present(collect($this->timetable()->update($s, $request->except('scope'), $scope)))]);
    }

    public function cancel(Request $request, string $session): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300'], 'scope' => ['sometimes', Rule::in(['one', 'following'])]]);
        $s = $this->session($request, $session);

        return response()->json(['data' => $this->present(collect($this->timetable()->cancel($s, $data['reason'], $data['scope'] ?? 'one')))]);
    }

    public function rooms(Request $request, string $centre): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $this->centre($request, $centre, 'cohorts.view');

        return response()->json(['data' => Room::query()->where('centre_id', $centre)->orderBy('name')->get()->values()->all(), 'kinds' => Room::KINDS]);
    }

    public function saveRoom(Request $request, string $centre, ?string $room = null): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $this->centre($request, $centre, 'cohorts.manage');
        $data = $request->validate([
            'name' => [$room ? 'sometimes' : 'required', 'string', 'min:1', 'max:80', Rule::unique('rooms', 'name')->where('centre_id', $centre)->ignore($room)],
            'capacity' => ['nullable', 'integer', 'between:1,1000'],
            'kind' => ['sometimes', Rule::in(array_keys(Room::KINDS))],
            'active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'This centre already has a room with that name.']);
        $r = $room ? Room::query()->where('centre_id', $centre)->whereKey($room)->firstOrFail() : new Room(['centre_id' => $centre]);
        $r->fill($data)->save();

        return response()->json(['data' => $r], $room ? 200 : 201);
    }

    /** The signed-in person's classes: as a learner (enrolled cohorts) and as a facilitator. */
    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        /** @var User $me */
        $me = $request->user();
        $from = CarbonImmutable::parse((string) ($data['from'] ?? 'today'), TimetableService::TZ)->startOfDay()->utc();
        $to = CarbonImmutable::parse((string) ($data['to'] ?? now()->addDays(13)->toDateString()), TimetableService::TZ)->endOfDay()->utc();
        $cohorts = Enrolment::query()->where('user_id', $me->id)->where('status', 'active')->pluck('cohort_id');
        $sessions = ClassSession::query()->where(fn ($q) => $q->whereIn('cohort_id', $cohorts)->orWhere('facilitator_id', $me->id))
            ->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->limit(300)->get();
        $names = Cohort::query()->whereIn('id', $sessions->pluck('cohort_id'))->pluck('name', 'id');

        return response()->json(['data' => array_map(fn (array $s) => $s + ['cohort' => $names->get($s['cohort_id']), 'teaching' => ($s['facilitator']['id'] ?? null) === $me->id],
            $this->present($sessions))]);
    }

    /**
     * @param  Collection<int, ClassSession>  $sessions
     * @return list<array<string, mixed>>
     */
    private function present(Collection $sessions): array
    {
        $modules = QualificationModule::query()->whereIn('id', $sessions->pluck('module_id')->filter())->get()->keyBy('id');
        $rooms = Room::query()->whereIn('id', $sessions->pluck('room_id')->filter())->get()->keyBy('id');
        $people = User::query()->whereIn('id', $sessions->pluck('facilitator_id')->filter())->get()->keyBy('id');

        return array_values($sessions->map(fn (ClassSession $s) => TimetableService::present($s, $modules, $rooms, $people))->all());
    }

    private function cohort(Request $request, string $id, string $permission): Cohort
    {
        $c = Cohort::query()->whereKey($id)->firstOrFail();
        $this->centre($request, $c->centre_id, $permission);

        return $c;
    }

    private function session(Request $request, string $id): ClassSession
    {
        $s = ClassSession::query()->whereKey($id)->firstOrFail();
        $this->cohort($request, $s->cohort_id, 'cohorts.manage');

        return $s;
    }

    private function centre(Request $request, string $centreId, string $permission): void
    {
        abort_unless(Centre::query()->whereKey($centreId)->exists(), 404);
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($centreId, $centres, true), 404);
    }

    private function timetable(): TimetableService
    {
        return app(TimetableService::class);
    }
}
