<?php

declare(strict_types=1);

namespace Modules\Access;

use Modules\Access\Models\CustomRole;
use Modules\Tenancy\Context\TenantContext;

/**
 * The current provider's own roles, in the shape of config roles. Scoped to the
 * request or job (bound with `scoped`), and read under row-level security, so a
 * provider only ever sees its own.
 */
final class CustomRoles
{
    /** @var array<string, array<string, array{label: string, scope: string, mfa: bool, permissions: list<string>, custom: bool}>> */
    private array $loaded = [];

    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, array{label: string, scope: string, mfa: bool, permissions: list<string>, custom: bool}> */
    public function current(): array
    {
        $tenant = $this->context->tenantId();
        if ($tenant === null) {
            return [];
        }

        return $this->loaded[$tenant] ??= CustomRole::query()->orderBy('name')->get()
            ->mapWithKeys(fn (CustomRole $r): array => [$r->key => [
                'label' => $r->name, 'scope' => $r->scope, 'mfa' => $r->mfa, 'permissions' => $r->permissions, 'custom' => true,
            ]])->all();
    }

    public function forget(): void
    {
        $this->loaded = [];
    }
}
