<?php

declare(strict_types=1);

namespace Modules\Cohorts\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Notifications\CohortAtRisk;
use Modules\Cohorts\Services\Readiness;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Daily: re-runs the readiness check on open cohorts and emails owners about new failures, once each. */
final class WatchCohorts extends Command
{
    protected $signature = 'cohorts:watch';

    protected $description = 'Warn providers when an open cohort starts failing its readiness check';

    public function handle(TenantContext $context, Readiness $readiness, AuditLogger $audit): int
    {
        $sent = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $readiness, $audit, &$sent): void {
                foreach (Cohort::query()->where('status', 'open')->get() as $cohort) {
                    $fails = array_values(array_filter($readiness->check($cohort)['items'], fn ($i) => $i['status'] === 'fail'));
                    $keys = array_column($fails, 'key');
                    $new = array_values(array_filter($fails, fn ($f) => ! in_array($f['key'], $cohort->watch_fails ?? [], true)));
                    $cohort->forceFill(['watch_fails' => $keys])->saveQuietly(); // cleared items can be reported again later
                    if ($new === []) {
                        continue;
                    }
                    $owners = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                        ->where('r.role', 'tp-owner')->whereNotNull('u.email')->distinct()->pluck('u.email');
                    foreach ($owners as $email) {
                        Notification::route('mail', (string) $email)->notify(new CohortAtRisk($tenant->trading_name, $tenant->code, $cohort->id, $cohort->name,
                            array_map(fn ($f) => ['title' => $f['title'], 'detail' => $f['detail']], $new)));
                    }
                    $audit->record('cohort.at_risk', $cohort, meta: ['items' => array_column($new, 'key')]);
                    $sent++;
                }
            });
        }
        $this->info("{$sent} cohorts reported");

        return self::SUCCESS;
    }
}
