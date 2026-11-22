<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Access\Catalogue;
use Modules\Access\CustomRoles;
use Modules\Access\Models\CustomRole;
use Modules\Access\Models\RoleAssignment;
use Modules\Audit\AuditLogger;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;

/**
 * Providers' own roles: any mix of the permissions an owner holds (never platform
 * permissions, never the right to build roles), centre- or provider-wide.
 */
final class CustomRoleService
{
    /** Permissions that change who can do what, see money or change records: always need MFA. */
    private const SENSITIVE_PREFIXES = ['tenant.', 'credentials.manage', 'people.manage', 'programmes.manage', 'centres.manage'];

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly CustomRoles $roles,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function save(array $input, ?CustomRole $existing, User $actor): CustomRole
    {
        $data = Validator::make($input, [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'min:2', 'max:80',
                Rule::unique('custom_roles', 'name')->where('tenant_id', $this->context->tenantId())->ignore($existing?->id)],
            'description' => ['nullable', 'string', 'max:300'],
            'scope' => [$existing ? 'sometimes' : 'required', 'in:tenant,centre'],
            'permissions' => [$existing ? 'sometimes' : 'required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in($this->catalogue->customisablePermissions())],
            'based_on' => ['nullable', 'string', Rule::in(array_keys(array_filter($this->catalogue->builtInRoles(), fn (array $r) => $r['scope'] !== 'platform')))],
        ], [
            'permissions.*.in' => 'Custom roles can only use permissions an owner holds, and cannot build roles.',
            'name.unique' => 'You already have a role with this name.',
        ])->validate();

        if (isset($data['name']) && collect($this->catalogue->builtInRoles())->contains(fn (array $r) => strcasecmp($r['label'], (string) $data['name']) === 0)) {
            throw ValidationException::withMessages(['name' => 'That name is used by a standard role. Choose another.']);
        }

        $role = $existing ?? new CustomRole(['key' => CustomRole::PREFIX.strtolower((string) Str::ulid()), 'created_by' => $actor->id]);
        $role->fill($data);
        $role->permissions = array_values(array_unique($role->permissions));
        $role->mfa = collect($role->permissions)->contains(fn (string $p) => collect(self::SENSITIVE_PREFIXES)->contains(fn (string $s) => str_starts_with($p, $s)));

        if ($existing !== null && $existing->isDirty('scope') && RoleAssignment::query()->where('role', $existing->key)->exists()) {
            throw ValidationException::withMessages(['scope' => 'People hold this role: remove it from them before changing where it applies.']);
        }

        $role->save();
        $this->roles->forget();
        $this->audit->record($existing ? 'role.custom_updated' : 'role.custom_created', $role, [], ['permissions' => $role->permissions, 'by' => $actor->id]);

        return $role;
    }

    public function delete(CustomRole $role, User $actor): void
    {
        $held = RoleAssignment::query()->where('role', $role->key)->count();
        if ($held > 0) {
            throw ValidationException::withMessages(['role' => "{$held} ".($held === 1 ? 'person holds' : 'people hold').' this role. Remove it from them first.']);
        }

        $role->delete();
        $this->roles->forget();
        $this->audit->record('role.custom_deleted', null, [], ['key' => $role->key, 'name' => $role->name, 'by' => $actor->id]);
    }
}
