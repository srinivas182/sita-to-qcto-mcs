<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Audit\AuditLogger;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Database\Seeders\CellSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->runAsPlatform(
            fn () => app(AuditLogger::class)->withoutAuditing(fn () => $this->call([CellSeeder::class])),
        );
    }
}
