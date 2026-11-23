<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\RegisterSignature;
use Modules\Delivery\Services\RegisterService;
use Modules\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

/** Monthly attendance registers: made, signed by learners and facilitators, downloaded and sent. */
final class RegisterController
{
    public function index(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.view');
        $c = $this->cohort($request, $cohort, 'cohorts.view');

        return response()->json(['data' => AttendanceRegister::query()->where('cohort_id', $c->id)->orderByDesc('month')->get()
            ->map(fn (AttendanceRegister $r) => $this->registers()->present($r, false))->values()->all()]);
    }

    public function store(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $c = $this->cohort($request, $cohort, 'cohorts.manage');
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => $this->registers()->present($this->registers()->make($c, $data['month'], $by))], 201);
    }

    public function show(Request $request, string $register): JsonResponse
    {
        $r = AttendanceRegister::query()->whereKey($register)->firstOrFail();
        /** @var User $me */
        $me = $request->user();
        $data = $this->registers()->present($r);
        if ($this->staff($request, $r)) {
            return response()->json(['data' => $data]);
        }
        $mine = RegisterSignature::query()->where('register_id', $r->id)->where('user_id', $me->id)->pluck('role');
        abort_if($mine->isEmpty(), 404);
        if ($mine->contains('mentor')) {
            // A workplace mentor sees the workplace days of the learners placed with them, not their classes.
            $data['grid']['sessions'] = [];
            $data['grid']['learners'] = array_values(array_map(fn (array $l) => ['marks' => []] + $l,
                array_filter($data['grid']['learners'], fn (array $l) => in_array($me->id, $l['workplace_mentors'] ?? [], true))));
            $data['signatures'] = array_values(array_filter($data['signatures'], fn (array $s) => $s['user_id'] === $me->id));

            return response()->json(['data' => $data]);
        }
        // A learner sees only their own line.
        $data['grid']['learners'] = array_values(array_filter($data['grid']['learners'], fn (array $l) => $l['user_id'] === $me->id));
        $data['signatures'] = array_values(array_filter($data['signatures'], fn (array $s) => $s['user_id'] === $me->id));

        return response()->json(['data' => $data]);
    }

    public function pdf(Request $request, string $register): Response
    {
        $r = AttendanceRegister::query()->whereKey($register)->firstOrFail();
        abort_unless($this->staff($request, $r), 404);
        $name = 'attendance-register-'.$r->month->format('Y-m').'-'.str((string) Cohort::query()->whereKey($r->cohort_id)->value('name'))->slug().'.pdf';

        return response($this->registers()->pdf($r), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store']);
    }

    public function sign(Request $request, string $register): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'role' => ['sometimes', Rule::in(['learner', 'facilitator', 'mentor'])]]);
        $r = AttendanceRegister::query()->whereKey($register)->firstOrFail();
        /** @var User $me */
        $me = $request->user();
        $role = $data['role'] ?? 'learner';
        if ($role === 'facilitator') {
            Gate::authorize('learning.deliver');
        }
        $this->registers()->sign($r, $me, $role, $data['name'], $this->channel($request), $request->ip());

        return response()->json(['data' => $this->registers()->present($r->refresh(), false)]);
    }

    public function dispute(Request $request, string $register): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $r = AttendanceRegister::query()->whereKey($register)->firstOrFail();
        /** @var User $me */
        $me = $request->user();
        $this->registers()->dispute($r, $me, $data['reason'], $this->channel($request), $request->ip());

        return response()->json(['data' => $this->registers()->present($r->refresh(), false)]);
    }

    public function submitted(Request $request, string $register): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $data = $request->validate(['sent_on' => ['required', 'date', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:300']]);
        $r = AttendanceRegister::query()->whereKey($register)->firstOrFail();
        $this->cohort($request, $r->cohort_id, 'cohorts.manage');

        return response()->json(['data' => $this->registers()->present($this->registers()->submitted($r, $data['sent_on'], $data['note'] ?? null), false)]);
    }

    public function remind(Request $request, string $register): JsonResponse
    {
        Gate::authorize('cohorts.manage');
        $r = AttendanceRegister::query()->whereKey($register)->firstOrFail();
        $this->cohort($request, $r->cohort_id, 'cohorts.manage');

        return response()->json(['data' => ['reminded' => $this->registers()->remind($r)]]);
    }

    /** Registers the signed-in person is on: theirs to sign first. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $sigs = RegisterSignature::query()->where('user_id', $me->id)->get()->keyBy('register_id');
        $rows = AttendanceRegister::query()->whereIn('id', $sigs->keys())->orderByDesc('month')->get();
        $names = Cohort::query()->whereIn('id', $rows->pluck('cohort_id'))->pluck('name', 'id');

        return response()->json(['data' => $rows->map(function (AttendanceRegister $r) use ($sigs, $names, $me): array {
            $s = $sigs->get($r->id);
            $line = collect($r->grid['learners'])->firstWhere('user_id', $me->id);

            return ['id' => $r->id, 'cohort' => $names->get($r->cohort_id), 'month' => $r->month->format('Y-m'), 'status' => $r->status, 'role' => $s?->role,
                'my_status' => $s?->status, 'attended' => $line['attended'] ?? null, 'held' => $line['held'] ?? null, 'pct' => $line['pct'] ?? null];
        })->sortBy(fn (array $x) => $x['my_status'] === 'pending' ? 0 : 1)->values()->all()]);
    }

    private function channel(Request $request): string
    {
        return $request->header('X-Client') === 'mobile' ? 'app' : 'web';
    }

    private function staff(Request $request, AttendanceRegister $r): bool
    {
        /** @var User $user */
        $user = $request->user();
        $centre = (string) Cohort::query()->whereKey($r->cohort_id)->value('centre_id');
        if (Gate::allows('cohorts.view')) {
            $centres = app(AccessService::class)->centresFor($user, 'cohorts.view');
            if ($centres === null || in_array($centre, $centres, true)) {
                return true;
            }
        }

        return Gate::allows('learning.deliver') && RegisterSignature::query()->where('register_id', $r->id)->where('user_id', $user->id)->where('role', 'facilitator')->exists();
    }

    private function cohort(Request $request, string $id, string $permission): Cohort
    {
        $c = Cohort::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($c->centre_id, $centres, true), 404);

        return $c;
    }

    private function registers(): RegisterService
    {
        return app(RegisterService::class);
    }
}
