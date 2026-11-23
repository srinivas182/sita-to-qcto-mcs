<?php

declare(strict_types=1);

namespace Modules\Delivery\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\DeliverySettings;
use Modules\Delivery\Notifications\AttendanceAtRisk;
use Modules\Delivery\Services\AttendanceService;
use Modules\Delivery\Services\TimetableService;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;
use Modules\Messaging\Services\MessageService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Daily: learners below the provider's attendance threshold (after at least four classes)
 * get a message, at most once a week, and the provider gets one email per cohort.
 */
final class WatchAttendance extends Command
{
    public const MIN_CLASSES = 4;

    protected $signature = 'delivery:attendance-watch';

    protected $description = 'Tell learners below the attendance threshold, and their provider';

    public function handle(TenantContext $context, AttendanceService $attendance, MessageService $messages, PushService $pushes, AuditLogger $audit): int
    {
        $flagged = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $attendance, $messages, $pushes, $audit, $context, &$flagged): void {
                $cohorts = Cohort::query()->whereIn('id', Enrolment::query()->where('status', 'active')->select('cohort_id'))->get();
                foreach ($cohorts as $cohort) {
                    $report = $attendance->report($cohort, CarbonImmutable::parse($cohort->intake_on, TimetableService::TZ)->startOfDay(), CarbonImmutable::now(TimetableService::TZ));
                    $threshold = DeliverySettings::current()->threshold_pct;
                    $newly = [];
                    foreach ($report['learners'] as $row) {
                        if (! $row['at_risk'] || $row['held'] - $row['excused'] < self::MIN_CLASSES) {
                            continue;
                        }
                        $e = Enrolment::query()->where('cohort_id', $cohort->id)->where('user_id', $row['user_id'])->where('status', 'active')->first();
                        if ($e === null || ($e->attendance_alerted_at !== null && $e->attendance_alerted_at->gt(now()->subDays(7)))) {
                            continue;
                        }
                        $phone = $context->runAsPlatform(fn () => User::query()->whereKey($row['user_id'])->value('phone_e164'));
                        $text = "{$tenant->trading_name}: your attendance for {$cohort->name} is {$row['pct']}%, below the {$threshold}% needed. If something is keeping you away, please speak to your facilitator.";
                        if (is_string($phone)) {
                            $messages->notify($phone, $text, 'attendance');
                        }
                        foreach (Device::query()->where('user_id', $row['user_id'])->whereNull('revoked_at')->whereNotNull('push_token')->get() as $d) {
                            try {
                                $pushes->send($d, 'Your attendance', "{$row['pct']}% for {$cohort->name}", ['type' => 'attendance']);
                            } catch (Throwable $ex) {
                                report($ex);
                            }
                        }
                        $e->forceFill(['attendance_alerted_at' => now()])->saveQuietly();
                        $newly[] = ['name' => (string) $row['name'], 'pct' => (int) $row['pct']];
                    }
                    if ($newly === []) {
                        continue;
                    }
                    $emails = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                        ->where(fn ($q) => $q->where('r.role', 'tp-owner')->orWhere(fn ($w) => $w->where('r.role', 'centre-manager')->where('r.centre_id', $cohort->centre_id)))
                        ->whereNotNull('u.email')->distinct()->pluck('u.email');
                    foreach ($emails as $email) {
                        Notification::route('mail', (string) $email)->notify(new AttendanceAtRisk($tenant->trading_name, $tenant->code, $cohort->id, $cohort->name, $threshold, $newly));
                    }
                    $audit->record('attendance.at_risk', $cohort, meta: ['learners' => count($newly)]);
                    $flagged += count($newly);
                }
            });
        }
        $this->info("{$flagged} learners told");

        return self::SUCCESS;
    }
}
