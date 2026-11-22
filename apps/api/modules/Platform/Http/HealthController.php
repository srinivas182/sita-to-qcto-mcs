<?php

declare(strict_types=1);

namespace Modules\Platform\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness and readiness probes for load balancers and uptime monitors.
 *
 * /health answers as long as PHP runs (no dependencies), so a restart is only
 * triggered when the process is truly stuck. /ready checks the database and
 * Redis so traffic is only routed to an instance that can serve it.
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'api',
            'version' => (string) config('app.version'),
            'cell' => (string) config('platform.cell'),
            'time' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json(
            ['status' => $ready ? 'ready' : 'degraded', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
