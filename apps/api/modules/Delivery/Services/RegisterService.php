<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\Attendance;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Models\RegisterSignature;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;
use Modules\Messaging\Services\MessageService;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * The monthly attendance register a SETA asks for (stipends are paid on it): a snapshot of
 * the month's classes and each learner's marks, signed in the app by each learner (or
 * disputed, with a reason) and by the facilitators who taught, then sent by the provider.
 */
final class RegisterService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly MessageService $messages,
        private readonly PushService $pushes,
    ) {}

    public function make(Cohort $cohort, string $month, ?User $by): AttendanceRegister
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, TimetableService::TZ)
            ?: throw ValidationException::withMessages(['month' => 'Choose a month.']);
        if ($start->endOfMonth()->isFuture()) {
            throw ValidationException::withMessages(['month' => 'A register is made once the month has ended.']);
        }
        $existing = AttendanceRegister::query()->where('cohort_id', $cohort->id)->whereDate('month', $start->toDateString())->first();
        if ($existing?->status === 'submitted') {
            throw ValidationException::withMessages(['month' => 'This register was already sent to the SETA.']);
        }
        $grid = $this->grid($cohort, $start);
        if ($grid['sessions'] === []) {
            throw ValidationException::withMessages(['month' => 'There were no classes in that month.']);
        }

        $register = DB::transaction(function () use ($cohort, $start, $grid, $by, $existing): AttendanceRegister {
            $r = $existing ?? new AttendanceRegister(['cohort_id' => $cohort->id, 'month' => $start->toDateString()]);
            $r->fill(['grid' => $grid, 'grid_sha256' => hash('sha256', (string) json_encode($grid)), 'created_by' => $by?->id]);
            $r->forceFill(['status' => 'signing', 'reminded_at' => null])->save();
            RegisterSignature::query()->where('register_id', $r->id)->delete(); // made again: everyone signs the new version
            foreach ($grid['learners'] as $l) {
                RegisterSignature::query()->create(['register_id' => $r->id, 'user_id' => $l['user_id'], 'role' => 'learner']);
            }
            foreach (array_unique(array_filter(array_column($grid['sessions'], 'facilitator_id'))) as $f) {
                RegisterSignature::query()->create(['register_id' => $r->id, 'user_id' => $f, 'role' => 'facilitator']);
            }

            return $r;
        });
        $this->audit->record($existing ? 'register.remade' : 'register.made', $register, [], ['month' => $start->format('Y-m'), 'learners' => count($grid['learners'])]);
        $this->ask($register, array_values(RegisterSignature::query()->where('register_id', $register->id)->get()->all()));

        return $register;
    }

    /** A learner or facilitator signs with their typed full name, or a learner disputes their line. */
    public function sign(AttendanceRegister $register, User $person, string $role, string $name, string $channel, ?string $ip): RegisterSignature
    {
        $sig = $this->signature($register, $person, $role);
        if (self::norm($name) !== self::norm($person->fullName())) {
            throw ValidationException::withMessages(['name' => "Type your full name as it appears: {$person->fullName()}."]);
        }
        $sig->forceFill(['status' => 'signed', 'signed_name' => trim($name), 'signed_at' => now(), 'ip' => $ip, 'channel' => $channel, 'dispute' => null])->save();
        $this->audit->record('register.signed', $register, [], ['role' => $role]);
        $this->settle($register);

        return $sig;
    }

    public function dispute(AttendanceRegister $register, User $learner, string $reason, string $channel, ?string $ip): RegisterSignature
    {
        $sig = $this->signature($register, $learner, 'learner');
        $sig->forceFill(['status' => 'disputed', 'dispute' => $reason, 'signed_at' => now(), 'ip' => $ip, 'channel' => $channel])->save();
        $this->audit->record('register.disputed', $register, [], ['reason' => $reason]);

        return $sig;
    }

    public function submitted(AttendanceRegister $register, string $on, ?string $note): AttendanceRegister
    {
        if ($register->status !== 'complete') {
            throw ValidationException::withMessages(['status' => 'The facilitator must sign the register first.']);
        }
        $register->forceFill(['status' => 'submitted', 'submitted_on' => $on, 'submitted_note' => $note])->save();
        $this->audit->record('register.submitted', $register, [], ['on' => $on]);

        return $register;
    }

    /** Remind everyone who has not signed yet (at most every three days). */
    public function remind(AttendanceRegister $register): int
    {
        $pending = array_values(RegisterSignature::query()->where('register_id', $register->id)->where('status', 'pending')->get()->all());
        $this->ask($register, $pending, true);
        $register->forceFill(['reminded_at' => now()])->save();

        return count($pending);
    }

    /** @return array<string, mixed> */
    public function present(AttendanceRegister $r, bool $withGrid = true): array
    {
        $sigs = RegisterSignature::query()->where('register_id', $r->id)->get();
        $names = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $sigs->pluck('user_id'))->get()->keyBy('id'));

        return ['id' => $r->id, 'cohort_id' => $r->cohort_id, 'cohort' => Cohort::query()->whereKey($r->cohort_id)->value('name'), 'month' => $r->month->format('Y-m'),
            'status' => $r->status, 'submitted_on' => $r->submitted_on?->toDateString(), 'submitted_note' => $r->submitted_note,
            'counts' => ['learners' => $sigs->where('role', 'learner')->count(), 'signed' => $sigs->where('role', 'learner')->where('status', 'signed')->count(),
                'disputed' => $sigs->where('status', 'disputed')->count(), 'facilitators_signed' => $sigs->where('role', 'facilitator')->where('status', 'signed')->count(),
                'facilitators' => $sigs->where('role', 'facilitator')->count()],
            'signatures' => $sigs->map(fn (RegisterSignature $s) => ['id' => $s->id, 'user_id' => $s->user_id, 'name' => $names->get($s->user_id)?->fullName(), 'role' => $s->role,
                'status' => $s->status, 'dispute' => $s->dispute, 'signed_at' => $s->signed_at?->toIso8601String()])->values()->all(),
        ] + ($withGrid ? ['grid' => $r->grid] : []);
    }

    /** The register as a PDF (A4 landscape): grid, totals, signatures and a fingerprint of the snapshot. */
    public function pdf(AttendanceRegister $r): string
    {
        $cohort = Cohort::query()->whereKey($r->cohort_id)->firstOrFail();
        $tenantId = $r->tenant_id;
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $html = view('delivery::register', [
            'provider' => $tenant->legal_name ?: $tenant->trading_name, 'cohort' => $cohort, 'centre' => Centre::query()->whereKey($cohort->centre_id)->first(),
            'qualification' => Qualification::query()->whereKey($cohort->qualification_id)->first(), 'register' => $r, 'data' => $this->present($r),
            'month' => $r->month->format('F Y'),
        ])->render();
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $this->audit->record('register.downloaded', $r);

        return (string) $pdf->output();
    }

    /**
     * @return array{sessions: list<array<string, mixed>>, learners: list<array<string, mixed>>}
     */
    private function grid(Cohort $cohort, CarbonImmutable $start): array
    {
        $end = $start->endOfMonth();
        $sessions = ClassSession::query()->where('cohort_id', $cohort->id)->where('status', 'scheduled')
            ->whereBetween('starts_at', [$start->utc(), $end->utc()])->where('starts_at', '<=', now())->orderBy('starts_at')->get();
        $modules = QualificationModule::query()->whereIn('id', $sessions->pluck('module_id')->filter())->pluck('code', 'id');
        $enrolments = Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('status', ['active', 'suspended', 'completed', 'withdrawn'])
            ->whereDate('enrolled_on', '<=', $end->toDateString())->where(fn ($q) => $q->whereNull('ended_on')->orWhereDate('ended_on', '>=', $start->toDateString()))->get();
        $people = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $enrolments->pluck('user_id'))->get()->keyBy('id'));
        $marks = Attendance::query()->whereIn('session_id', $sessions->pluck('id'))->get()->groupBy('user_id');

        $rows = [];
        foreach ($enrolments as $e) {
            $u = $people->get($e->user_id);
            if ($u === null) {
                continue;
            }
            $mine = ($marks->get($e->user_id) ?? collect())->keyBy('session_id');
            $cells = [];
            $held = $attended = $excused = 0;
            foreach ($sessions as $s) {
                $day = CarbonImmutable::parse($s->starts_at)->setTimezone(TimetableService::TZ)->toDateString();
                if ($day < $e->enrolled_on->toDateString() || ($e->ended_on !== null && $day > $e->ended_on->toDateString())) {
                    $cells[$s->id] = null; // not enrolled then

                    continue;
                }
                $status = $mine->get($s->id)->status ?? 'absent';
                $cells[$s->id] = $status;
                $held++;
                $attended += in_array($status, Attendance::ATTENDED, true) ? 1 : 0;
                $excused += $status === 'excused' ? 1 : 0;
            }
            $counted = $held - $excused;
            $rows[] = ['user_id' => $u->id, 'name' => $u->fullName(), 'id_number' => $u->maskedIdNumber(), 'marks' => $cells, 'held' => $held, 'attended' => $attended,
                'excused' => $excused, 'pct' => $counted > 0 ? (int) round($attended * 100 / $counted) : null];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'sessions' => array_values($sessions->map(function (ClassSession $s) use ($modules): array {
                $l = CarbonImmutable::parse($s->starts_at)->setTimezone(TimetableService::TZ);

                return ['id' => $s->id, 'date' => $l->toDateString(), 'start' => $l->format('H:i'),
                    'end' => CarbonImmutable::parse($s->ends_at)->setTimezone(TimetableService::TZ)->format('H:i'),
                    'kind' => $s->kind, 'module' => $modules->get($s->module_id), 'facilitator_id' => $s->facilitator_id];
            })->all()),
            'learners' => $rows,
        ];
    }

    private function signature(AttendanceRegister $register, User $person, string $role): RegisterSignature
    {
        if ($register->status === 'submitted') {
            throw ValidationException::withMessages(['register' => 'This register has already been sent to the SETA.']);
        }

        return RegisterSignature::query()->where('register_id', $register->id)->where('user_id', $person->id)->where('role', $role)->first()
            ?? throw ValidationException::withMessages(['register' => 'You are not on this register.']);
    }

    /** Complete once every facilitator who taught has signed. */
    private function settle(AttendanceRegister $register): void
    {
        $open = RegisterSignature::query()->where('register_id', $register->id)->where('role', 'facilitator')->where('status', '!=', 'signed')->exists();
        if (! $open && $register->status === 'signing') {
            $register->forceFill(['status' => 'complete'])->save();
        }
    }

    /** @param  list<RegisterSignature>  $signatures */
    private function ask(AttendanceRegister $register, array $signatures, bool $reminder = false): void
    {
        if ($signatures === []) {
            return;
        }
        $tenantId = $register->tenant_id;
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $month = $register->month->format('F Y');
        $link = 'https://'.$tenant->code.'.'.config('platform.base_domain').'/my-registers/'.$register->id;
        $ids = array_map(fn (RegisterSignature $s) => $s->user_id, $signatures);
        $phones = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $ids)->pluck('phone_e164', 'id'));
        foreach ($signatures as $s) {
            $phone = $phones->get($s->user_id);
            if (is_string($phone)) {
                $text = ($reminder ? 'Reminder: ' : '')."please check and sign your attendance register for {$month}: {$link}";
                $this->messages->notify($phone, "{$tenant->trading_name}: {$text}", 'attendance');
            }
        }
        foreach (Device::query()->whereIn('user_id', $ids)->whereNull('revoked_at')->whereNotNull('push_token')->get() as $device) {
            try {
                $this->pushes->send($device, 'Sign your attendance register', $month, ['type' => 'register', 'register_id' => $register->id]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private static function norm(string $s): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($s)));
    }
}
