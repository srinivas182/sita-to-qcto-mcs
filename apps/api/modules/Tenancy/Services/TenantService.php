<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Tenancy\Cells\CellPlacement;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Domains\DomainService;
use Modules\Tenancy\Enums\TenantStatus;
use Modules\Tenancy\Events\TenantRegistered;
use Modules\Tenancy\Events\TenantStatusChanged;
use Modules\Tenancy\Exceptions\TenantError;
use Modules\Tenancy\Models\Cell;
use Modules\Tenancy\Models\Tenant;

/**
 * Training-provider lifecycle: register, verify, activate, suspend, reactivate.
 *
 * These are platform operations, so they run in the platform context. Callers
 * (Super Admin endpoints, the public application form) authorise first.
 */
final class TenantService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CellPlacement $placement,
        private readonly DomainService $domains,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  array{code: string, legal_name: string, trading_name?: string|null, registration_number?: string|null, package_code?: string|null, branding?: array<string, mixed>|null}  $data
     */
    public function register(array $data, ?Cell $cell = null, ?string $createdBy = null): Tenant
    {
        $code = $this->validCode($data['code']);

        return $this->context->runAsPlatform(function () use ($data, $code, $cell, $createdBy): Tenant {
            $cell ??= $this->placement->choose();

            try {
                $tenant = $this->db->transaction(function () use ($data, $code, $cell, $createdBy): Tenant {
                    $tenant = Tenant::query()->create([
                        'cell_id' => $cell->id,
                        'code' => $code,
                        'legal_name' => trim($data['legal_name']),
                        'trading_name' => trim($data['trading_name'] ?? '') ?: trim($data['legal_name']),
                        'registration_number' => $data['registration_number'] ?? null,
                        'package_code' => $data['package_code'] ?? null,
                        'branding' => $data['branding'] ?? null,
                        'status' => TenantStatus::Pending,
                        'created_by' => $createdBy,
                    ]);

                    $this->domains->addSubdomain($tenant);

                    return $tenant;
                });
            } catch (UniqueConstraintViolationException) {
                throw TenantError::codeTaken($code);
            }

            TenantRegistered::dispatch($tenant);

            return $tenant;
        });
    }

    public function verify(Tenant $tenant): Tenant
    {
        return $this->transition($tenant, TenantStatus::Verified);
    }

    /** Super Admin approval: the provider goes live. */
    public function approve(Tenant $tenant): Tenant
    {
        return $this->transition($tenant, TenantStatus::Active, ['approved_at' => $tenant->approved_at ?? now()]);
    }

    public function suspend(Tenant $tenant, string $reason): Tenant
    {
        return $this->transition($tenant, TenantStatus::Suspended, [
            'suspended_at' => now(),
            'suspension_reason' => mb_substr(trim($reason), 0, 500),
        ]);
    }

    public function reactivate(Tenant $tenant): Tenant
    {
        return $this->transition($tenant, TenantStatus::Active, ['suspended_at' => null, 'suspension_reason' => null]);
    }

    /** Move a tenant to another cell (record only; data move is an ops runbook). */
    public function placeInCell(Tenant $tenant, Cell $cell): Tenant
    {
        return $this->context->runAsPlatform(function () use ($tenant, $cell): Tenant {
            $tenant->cell_id = $cell->id;
            $tenant->save();

            return $tenant;
        });
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(Tenant $tenant, TenantStatus $to, array $extra = []): Tenant
    {
        $from = $tenant->status;

        if (! $from->canTransitionTo($to)) {
            throw TenantError::transition($from, $to);
        }

        $this->context->runAsPlatform(function () use ($tenant, $to, $extra): void {
            $tenant->forceFill(['status' => $to, ...$extra])->save();
        });

        TenantStatusChanged::dispatch($tenant, $from, $to);

        return $tenant;
    }

    /** Normalise a provider code, or throw TenantError when it is not allowed. */
    public function validCode(string $code): string
    {
        $code = strtolower(trim($code));

        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/', $code) || str_contains($code, '--')) {
            throw TenantError::invalidCode($code);
        }

        /** @var list<string> $reserved */
        $reserved = config('platform.reserved_codes', []);

        if (in_array($code, $reserved, true)) {
            throw TenantError::reservedCode($code);
        }

        return $code;
    }
}
