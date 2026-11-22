<?php

declare(strict_types=1);

namespace Modules\Audit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Audit\Partitions;

final class EnsureAuditPartitions extends Command
{
    protected $signature = 'audit:partitions {--months=3 : How many months ahead to prepare}';

    protected $description = 'Create upcoming monthly partitions of the audit log';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $created = Partitions::ensure(Carbon::now()->startOfMonth(), $months + 1);

        $this->info($created === [] ? 'Audit partitions already in place.' : 'Created: '.implode(', ', $created));

        return self::SUCCESS;
    }
}
