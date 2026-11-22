<?php

declare(strict_types=1);

namespace Modules\Audit;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Support\Database\Rls;

/** Creates monthly partitions of audit_logs ahead of time. */
final class Partitions
{
    /**
     * Ensure partitions exist for `$months` months starting at `$from`.
     *
     * @return list<string> names of partitions created by this call
     */
    public static function ensure(CarbonInterface $from, int $months): array
    {
        $created = [];
        $start = $from->copy()->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonthsNoOverflow($i);
            $name = 'audit_logs_'.$month->format('Y_m');

            $exists = DB::selectOne('select to_regclass(?) as name', [$name]);

            if ($exists !== null && $exists->name !== null) {
                continue;
            }

            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF audit_logs FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $month->format('Y-m-01 00:00:00P'),
                $month->copy()->addMonthNoOverflow()->format('Y-m-01 00:00:00P'),
            ));

            // Partitions are tables of their own: protect them so direct queries are
            // isolated exactly like queries through the parent.
            Rls::protect($name);

            $created[] = $name;
        }

        return $created;
    }
}
