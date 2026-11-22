<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\BillingCycle;

final class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Renew plans, issue invoices, charge saved cards and apply overdue rules';

    public function handle(BillingCycle $cycle): int
    {
        $stats = $cycle->run();
        $this->info(sprintf('%d providers, %d invoices, %d charges, %d errors', $stats['providers'], $stats['invoices'], $stats['charges'], $stats['errors']));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
