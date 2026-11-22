<?php

declare(strict_types=1);

namespace Modules\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Billing\Services\BillingDocuments;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Renders an invoice or credit note PDF once and keeps it with the provider's files. */
final class RenderBillingDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId, public readonly string $type, public readonly string $id) {}

    public function handle(TenantContext $context, BillingDocuments $documents): void
    {
        $tenant = $context->runAsPlatform(fn () => Tenant::query()->find($this->tenantId));

        if ($tenant !== null) {
            $context->runAs($tenant, fn () => $documents->store($this->type, $this->id));
        }
    }
}
