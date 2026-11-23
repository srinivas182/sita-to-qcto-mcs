<?php

declare(strict_types=1);

namespace Modules\Enrolment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Notifications\QctoEnrolmentDue;
use Modules\Enrolment\Services\QctoEnrolmentList;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Daily: remind owners and centre managers 7 and 2 days before, on the day, and once overdue. */
final class QctoDueAlerts extends Command
{
    protected $signature = 'enrolment:qcto-due';

    protected $description = 'Remind providers to send QCTO learner enrolment files before they are due';

    public function handle(TenantContext $context, QctoEnrolmentList $list, AuditLogger $audit): int
    {
        $sent = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $list, $audit, &$sent): void {
                $cohortIds = Enrolment::query()->whereIn('status', ['active', 'suspended'])->distinct()->pluck('cohort_id');
                foreach (Cohort::query()->whereIn('id', $cohortIds)->get() as $cohort) {
                    $data = $list->build($cohort);
                    if ($data['unsent'] === 0) {
                        continue;
                    }
                    $days = $data['due']['days_left'];
                    $threshold = match (true) {
                        $days < 0 => -1,
                        $days === 0 => 0,
                        $days <= 2 => 2,
                        $days <= 7 => 7,
                        default => null,
                    };
                    if ($threshold === null || ($cohort->qcto_alerted_for !== null && $cohort->qcto_alerted_for <= $threshold)) {
                        continue;
                    }
                    $emails = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                        ->where(fn ($q) => $q->where('r.role', 'tp-owner')->orWhere(fn ($w) => $w->where('r.role', 'centre-manager')->where('r.centre_id', $cohort->centre_id)))
                        ->whereNotNull('u.email')->distinct()->pluck('u.email');
                    $notReady = count(array_filter($data['rows'], fn (array $r) => ! $r['sent'] && $r['errors'] !== []));
                    foreach ($emails as $email) {
                        Notification::route('mail', (string) $email)->notify(new QctoEnrolmentDue($tenant->trading_name, $tenant->code, $cohort->id, $cohort->name,
                            date('j F Y', (int) strtotime($data['due']['due_on'])), $days, $data['unsent'], $notReady));
                    }
                    $cohort->forceFill(['qcto_alerted_for' => $threshold])->saveQuietly();
                    $audit->record('qcto.enrolment_alert', $cohort, meta: ['days' => $days]);
                    $sent++;
                }
            });
        }
        $this->info("{$sent} reminders sent");

        return self::SUCCESS;
    }
}
