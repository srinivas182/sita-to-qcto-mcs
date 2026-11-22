<?php

declare(strict_types=1);

namespace Modules\Access;

use InvalidArgumentException;

/**
 * Read-only view of config/access.php: roles, their scope and permissions.
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
        return isset($this->roles[$role]);
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
        return $this->roles;
    }

    /** @return array{label: string, scope: string, mfa: bool, permissions: list<string>} */
    private function role(string $role): array
    {
        return $this->roles[$role] ?? throw new InvalidArgumentException("Unknown role [{$role}].");
    }
}
