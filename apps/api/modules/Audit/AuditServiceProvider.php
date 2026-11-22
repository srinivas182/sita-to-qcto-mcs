<?php

declare(strict_types=1);

namespace Modules\Audit;

use Modules\Audit\Console\EnsureAuditPartitions;
use Modules\Support\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditLogger::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnsureAuditPartitions::class]);
        }
    }
}
