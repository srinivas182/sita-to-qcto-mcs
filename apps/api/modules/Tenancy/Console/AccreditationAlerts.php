<?php

declare(strict_types=1);

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\AuditLogger;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Notifications\AccreditationExpiring;

/**
 * Daily: warns owners (and the centre's managers) 90, 60 and 30 days before a centre's
 * accreditation ends, and once it has ended. Each threshold is sent once.
 */
final class AccreditationAlerts extends Command
{
    protected $signature = 'centres:accreditation-alerts';

    protected $description = 'Email providers whose centre accreditation is ending or has ended';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $sent = 0;
        $tenants = $context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get());

        foreach ($tenants as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $audit, &$sent): void {
                $centres = Centre::query()->where('status', 'active')->whereNotNull('accreditation_expires_on')
                    ->whereDate('accreditation_expires_on', '<=', now()->addDays(90))->get();

                foreach ($centres as $centre) {
                    $days = (int) $centre->daysToExpiry();
                    $threshold = match (true) {
                        $days < 0 => 0,
                        $days <= 30 => 30,
                        $days <= 60 => 60,
                        default => 90,
                    };

                    if ($centre->alerted_for !== null && $centre->alerted_for <= $threshold) {
                        continue;
                    }

                    foreach ($this->recipients($centre) as $email) {
                        Notification::route('mail', $email)->notify(new AccreditationExpiring($tenant, $centre, $days));
                    }

                    $centre->forceFill(['alerted_for' => $threshold])->saveQuietly();
                    $audit->record('centre.accreditation_alert', $centre, meta: ['days' => $days, 'threshold' => $threshold]);
                    $sent++;
                }
            });
        }

        $this->info("{$sent} alerts sent");

        return self::SUCCESS;
    }

    /** @return list<string> owners, and managers of this centre */
    private function recipients(Centre $centre): array
    {
        /** @var list<string> */
        return DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where(fn ($q) => $q->where('r.role', 'tp-owner')->orWhere(fn ($q) => $q->where('r.role', 'centre-manager')->where('r.centre_id', $centre->id)))
            ->whereNotNull('u.email')->distinct()->pluck('u.email')->all();
    }
}
