<?php

declare(strict_types=1);

namespace Modules\Access;

use InvalidArgumentException;
use Modules\Access\Models\CustomRole;

/**
 * Read-only view of config/access.php — roles, their scope and permissions — plus the
 * current provider's own roles (keys `custom:<ulid>`), so every permission check
 * treats both the same way.
 */
final class Catalogue
{
    /** @var array<string, array{label: string, scope: string, mfa: bool, permissions: list<string>}> */
    private array $roles;

    /** @var array<string, string> */
    private array $permissions;

    public function __construct()
    {
        /** @var array<string, array{label: string, scope: string, mfa: bool, permissions: list<string>}> $roles */
        $roles = config('access.roles', []);
        /** @var array<string, string> $permissions */
        $permissions = config('access.permissions', []);

        $this->roles = $roles;
        $this->permissions = $permissions;
    }

    public function hasRole(string $role): bool
    {
        return isset($this->roles[$role]) || (CustomRole::isCustom($role) && isset($this->custom()[$role]));
    }

    public function isCustom(string $role): bool
    {
        return CustomRole::isCustom($role);
    }

    /**
     * The permissions a provider may put into its own roles: what an owner holds.
     *
     * @return list<string>
     */
    public function customisablePermissions(): array
    {
        return array_values(array_diff($this->roles['tp-owner']['permissions'] ?? [], ['tenant.roles.manage']));
    }

    public function hasPermission(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function scope(string $role): string
    {
        return $this->role($role)['scope'];
    }

    public function isPlatformRole(string $role): bool
    {
        return $this->hasRole($role) && $this->scope($role) === 'platform';
    }

    public function requiresMfa(string $role): bool
    {
        return $this->hasRole($role) && $this->role($role)['mfa'];
    }

    /** @return list<string> */
    public function permissionsOf(string $role): array
    {
        return $this->hasRole($role) ? $this->role($role)['permissions'] : [];
    }

    /** @return list<string> */
    public function supportPermissions(): array
    {
        /** @var list<string> $list */
        $list = config('access.support_permissions', []);

        return $list;
    }

    /** @return list<string> */
    public function delegableByCentreManager(): array
    {
        /** @var list<string> $list */
        $list = config('access.delegable_by_centre_manager', []);

        return $list;
    }

    /** @return array<string, array{label: string, scope: string, mfa: bool, permissions: list<string>}> */
    public function roles(): array
    {
        return $this->roles + $this->custom();
    }

    /** @return array<string, array{label: string, scope: string, mfa: bool, permissions: list<string>}> */
    public function builtInRoles(): array
    {
        return $this->roles;
    }

    public function label(string $role): string
    {
        return $this->hasRole($role) ? $this->role($role)['label'] : $role;
    }

    /** @return array{label: string, scope: string, mfa: bool, permissions: list<string>} */
    private function role(string $role): array
    {
        return $this->roles[$role] ?? $this->custom()[$role] ?? throw new InvalidArgumentException("Unknown role [{$role}].");
    }

    /** @return array<string, array{label: string, scope: string, mfa: bool, permissions: list<string>}> */
    private function custom(): array
    {
        return app(CustomRoles::class)->current();
    }
}
