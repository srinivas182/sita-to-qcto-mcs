<?php

declare(strict_types=1);

namespace Modules\Delivery\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Models\AttendanceRegister;
use Modules\Delivery\Models\ClassSession;
use Modules\Delivery\Services\RegisterService;
use Modules\Delivery\Services\TimetableService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Daily: in the first days of a month, make last month's register for every cohort that had
 * classes (so it is ready for the SETA without anyone remembering), and remind people who
 * have not signed an open register for three days.
 */
final class Registers extends Command
{
    protected $signature = 'delivery:registers';

    protected $description = 'Make last month\'s attendance registers and remind unsigned people';

    public function handle(TenantContext $context, RegisterService $registers): int
    {
        $made = $reminded = 0;
        $today = CarbonImmutable::now(TimetableService::TZ);
        $last = $today->subMonthNoOverflow()->startOfMonth();
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($registers, $today, $last, &$made, &$reminded): void {
                if ($today->day <= 5) {
                    $cohortIds = ClassSession::query()->where('status', 'scheduled')
                        ->whereBetween('starts_at', [$last->utc(), $last->endOfMonth()->utc()])->distinct()->pluck('cohort_id');
                    foreach (Cohort::query()->whereIn('id', $cohortIds)->get() as $cohort) {
                        if (AttendanceRegister::query()->where('cohort_id', $cohort->id)->whereDate('month', $last->toDateString())->exists()) {
                            continue;
                        }
                        try {
                            $registers->make($cohort, $last->format('Y-m'), null);
                            $made++;
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }
                }
                $open = AttendanceRegister::query()->where('status', '!=', 'submitted')->where('created_at', '<=', now()->subDays(3))
                    ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', now()->subDays(3)))->get();
                foreach ($open as $r) {
                    $reminded += $registers->remind($r);
                }
            });
        }
        $this->info("{$made} registers made, {$reminded} people reminded");

        return self::SUCCESS;
    }
}
