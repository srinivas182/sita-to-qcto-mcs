<?php

declare(strict_types=1);

namespace Modules\Assessment\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Assessment\Models\EisaRegistration;
use Modules\Assessment\Models\StatementOfResults;
use Modules\Assessment\Notifications\EisaClosing;
use Modules\Assessment\Services\EisaService;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Daily: warn owners and centre managers 30, 14 and 3 days before an EISA registration closes, while ready learners are not registered. */
final class EisaClosingAlerts extends Command
{
    protected $signature = 'eisa:closing';

    protected $description = 'Warn providers before EISA registration closes for learners who are ready but not registered';

    public function handle(TenantContext $context, EisaService $eisa, AuditLogger $audit): int
    {
        $sent = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $eisa, $audit, &$sent): void {
                $ready = StatementOfResults::query()->where('status', 'issued')->get();
                $registered = EisaRegistration::query()->where('status', 'registered')->pluck('enrolment_id')->all();
                foreach ($ready->groupBy('cohort_id') as $cohortId => $sors) {
                    $waiting = $sors->filter(fn (StatementOfResults $s) => ! in_array($s->enrolment_id, $registered, true))->count();
                    $cohort = Cohort::query()->whereKey($cohortId)->first();
                    $next = $cohort ? $eisa->nextOpen($cohort->qualification_id) : null;
                    if ($cohort === null || $waiting === 0 || $next === null) {
                        continue;
                    }
                    $days = (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($next['closes_on']), false);
                    $step = match (true) {
                        $days <= 3 => 3, $days <= 14 => 14, $days <= 30 => 30, default => null
                    };
                    $alerted = $cohort->getAttribute('eisa_alerted_for');
                    if ($step === null || ($alerted !== null && (int) $alerted <= $step)) {
                        continue;
                    }
                    $emails = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                        ->where(fn ($q) => $q->where('r.role', 'tp-owner')->orWhere(fn ($w) => $w->where('r.role', 'centre-manager')->where('r.centre_id', $cohort->centre_id)))
                        ->whereNotNull('u.email')->distinct()->pluck('u.email');
                    foreach ($emails as $email) {
                        Notification::route('mail', (string) $email)->notify(new EisaClosing($tenant->trading_name, $tenant->code, $cohort->id, $cohort->name,
                            CarbonImmutable::parse($next['eisa_on'])->format('j F Y'), CarbonImmutable::parse($next['closes_on'])->format('j F Y'), $days, $waiting));
                    }
                    $cohort->forceFill(['eisa_alerted_for' => $step])->saveQuietly();
                    $audit->record('eisa.closing_alert', $cohort, meta: ['days' => $days, 'waiting' => $waiting]);
                    $sent++;
                }
            });
        }
        $this->info("{$sent} EISA reminders sent");

        return self::SUCCESS;
    }
}
