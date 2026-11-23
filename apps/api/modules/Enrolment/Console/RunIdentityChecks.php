<?php

declare(strict_types=1);

namespace Modules\Enrolment\Console;

use Illuminate\Console\Command;
use Modules\Enrolment\Services\IdentityCheckService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Nightly, off-peak: run the queued Home Affairs ID checks in one batch. */
final class RunIdentityChecks extends Command
{
    protected $signature = 'identity:run-checks {--limit=500 : Most checks per provider in one run}';

    protected $description = 'Run queued Home Affairs ID checks';

    public function handle(TenantContext $context, IdentityCheckService $checks): int
    {
        $done = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($checks, &$done): void {
                $done += $checks->runQueued((int) $this->option('limit'));
            });
        }
        $this->info("{$done} ID checks run");

        return self::SUCCESS;
    }
}
