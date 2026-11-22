<?php

declare(strict_types=1);

namespace Modules\Employers\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\AuditLogger;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Notifications\WorkplaceApprovalExpiring;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Daily: warns owners 90, 60 and 30 days before a workplace approval ends, and when it ends (once each). */
final class ApprovalExpiryAlerts extends Command
{
    protected $signature = 'employers:approval-alerts';

    protected $description = 'Email providers whose host employers\' workplace approvals are ending';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $sent = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $audit, &$sent): void {
                $due = EmployerSite::query()->where('status', 'active')->whereNotNull('approval_number')->whereNotNull('approval_expires_on')
                    ->whereDate('approval_expires_on', '<=', now()->addDays(90))->whereDate('approval_expires_on', '>=', now()->subDays(30))->get();
                if ($due->isEmpty()) {
                    return;
                }
                $owners = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                    ->where('r.role', 'tp-owner')->whereNotNull('u.email')->distinct()->pluck('u.email');
                $employers = Employer::query()->whereIn('id', $due->pluck('employer_id'))->pluck('legal_name', 'id');

                foreach ($due as $site) {
                    $days = (int) now()->startOfDay()->diffInDays($site->approval_expires_on, false);
                    $threshold = match (true) {
                        $days < 0 => 0,
                        $days <= 30 => 30,
                        $days <= 60 => 60,
                        default => 90,
                    };
                    if ($site->alerted_for !== null && $site->alerted_for <= $threshold) {
                        continue;
                    }
                    foreach ($owners as $email) {
                        Notification::route('mail', (string) $email)->notify(new WorkplaceApprovalExpiring(
                            $tenant->trading_name, $tenant->code, (string) $employers->get($site->employer_id), $site->name,
                            "{$site->approval_body} {$site->approval_number}", (string) $site->approval_expires_on?->format('j F Y'), $days,
                        ));
                    }
                    $site->forceFill(['alerted_for' => $threshold])->saveQuietly();
                    $audit->record('workplace.approval_alert', $site, meta: ['days' => $days, 'threshold' => $threshold]);
                    $sent++;
                }
            });
        }
        $this->info("{$sent} alerts sent");

        return self::SUCCESS;
    }
}
