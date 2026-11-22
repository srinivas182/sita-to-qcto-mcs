<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use Illuminate\Support\Collection;
use Modules\Access\Catalogue;
use Modules\Access\Exceptions\AccessError;
use Modules\Access\Models\PlatformRoleAssignment;
use Modules\Access\Models\RoleAssignment;
use Modules\Access\Models\SupportAccessGrant;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\MembershipStatus;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;

/**
 * Answers "may this person do X here?" and manages role assignments.
 *
 * Tenant roles are read in the current tenant context (row-level security keeps
 * other providers' assignments invisible); platform roles are read in the platform
 * context. Results are memoised for the request (scoped binding).
 */
final class AccessService
{
    /** @var array<string, list<string>> */
    private array $platform = [];

    /** @var array<string, Collection<int, RoleAssignment>> */
    private array $tenant = [];

    private ?SupportAccessGrant $grant = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Catalogue $catalogue,
    ) {}

    /** @return list<string> */
    public function platformRoles(User $user): array
    {
        return $this->platform[$user->id] ??= $this->context->runAsPlatform(
            fn (): array => array_values(PlatformRoleAssignment::query()->where('user_id', $user->id)->pluck('role')->all()),
        );
    }

    /** @return Collection<int, RoleAssignment> */
    public function tenantAssignments(User $user): Collection
    {
        $tenantId = $this->context->tenantId();

        if ($tenantId === null) {
            return new Collection;
        }

        return $this->tenant[$user->id.'|'.$tenantId] ??= RoleAssignment::query()->where('user_id', $user->id)->get();
    }

    /** @return list<string> distinct role names held here (platform + current tenant) */
    public function roles(User $user): array
    {
        return array_values(array_unique([...$this->platformRoles($user), ...$this->tenantAssignments($user)->pluck('role')->all()]));
    }

    public function isPlatformStaff(User $user): bool
    {
        return $this->platformRoles($user) !== [];
    }

    /** Record that the current request acts through an approved support grant. */
    public function actViaGrant(?SupportAccessGrant $grant): void
    {
        $this->grant = $grant;
    }

    public function activeGrant(): ?SupportAccessGrant
    {
        return $this->grant;
    }

    /**
     * @param  string|null  $centreId  limit the question to one centre; null = "anywhere here"
     */
    public function can(User $user, string $permission, ?string $centreId = null): bool
    {
        if (! $this->catalogue->hasPermission($permission)) {
            return false;
        }

        foreach ($this->platformRoles($user) as $role) {
            if (in_array($permission, $this->catalogue->permissionsOf($role), true)) {
                return true;
            }
        }

        if ($this->grant !== null && $this->grant->granted_to === $user->id && $this->grant->isActive()) {
            if (in_array($permission, $this->catalogue->supportPermissions(), true)) {
                return true;
            }
        }

        foreach ($this->tenantAssignments($user) as $assignment) {
            if (! in_array($permission, $this->catalogue->permissionsOf($assignment->role), true)) {
                continue;
            }

            if ($centreId === null || $assignment->centre_id === null || $assignment->centre_id === $centreId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Centres where the permission applies: null means every centre of the provider.
     *
     * @return list<string>|null
     */
    public function centresFor(User $user, string $permission): ?array
    {
        $centres = [];

        foreach ($this->platformRoles($user) as $role) {
            if (in_array($permission, $this->catalogue->permissionsOf($role), true)) {
                return null;
            }
        }

        if ($this->grant !== null && $this->grant->granted_to === $user->id && $this->grant->isActive()
            && in_array($permission, $this->catalogue->supportPermissions(), true)) {
            return null;
        }

        foreach ($this->tenantAssignments($user) as $assignment) {
            if (in_array($permission, $this->catalogue->permissionsOf($assignment->role), true)) {
                if ($assignment->centre_id === null) {
                    return null;
                }

                $centres[] = $assignment->centre_id;
            }
        }

        return array_values(array_unique($centres));
    }

    public function requiresMfa(User $user): bool
    {
        foreach ($this->roles($user) as $role) {
            if ($this->catalogue->requiresMfa($role)) {
                return true;
            }
        }

        return false;
    }

    public function isMember(User $user): bool
    {
        if (! $this->context->hasTenant()) {
            return false;
        }

        return UserTenant::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }

    /** Assign a tenant or centre role within the current tenant. */
    public function assign(User $user, string $role, ?string $centreId = null, ?User $by = null): RoleAssignment
    {
        $this->guardTenantRole($role, $centreId);

        if (! $this->isMember($user)) {
            throw AccessError::notMember();
        }

        $assignment = RoleAssignment::query()->firstOrCreate(
            ['user_id' => $user->id, 'role' => $role, 'centre_id' => $centreId],
            ['granted_by' => $by?->id],
        );

        $this->forget($user);

        return $assignment;
    }

    public function revoke(User $user, string $role, ?string $centreId = null): void
    {
        $assignment = RoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->where('centre_id', $centreId)
            ->first();

        if ($assignment === null) {
            return;
        }

        if ($role === 'tp-owner' && RoleAssignment::query()->where('role', 'tp-owner')->count() <= 1) {
            throw AccessError::lastOwner();
        }

        $assignment->delete();
        $this->forget($user);
    }

    /**
     * Replace a person's roles in the current tenant with the given set.
     *
     * @param  list<array{role: string, centre_id?: string|null}>  $roles
     */
    public function sync(User $user, array $roles, ?User $by = null): void
    {
        $wanted = collect($roles)->map(fn (array $r): string => $r['role'].'|'.($r['centre_id'] ?? ''));

        foreach ($roles as $r) {
            $this->guardTenantRole($r['role'], $r['centre_id'] ?? null);
        }

        foreach ($this->tenantAssignments($user) as $existing) {
            if (! $wanted->contains($existing->role.'|'.($existing->centre_id ?? ''))) {
                $this->revoke($user, $existing->role, $existing->centre_id);
            }
        }

        foreach ($roles as $r) {
            $this->assign($user, $r['role'], $r['centre_id'] ?? null, $by);
        }
    }

    /** Whether `$actor` may hand out `$role` in the current tenant. */
    public function mayDelegate(User $actor, string $role): bool
    {
        if (! $this->catalogue->hasRole($role) || $this->catalogue->isPlatformRole($role)) {
            return false;
        }

        if (in_array('super-admin', $this->platformRoles($actor), true)) {
            return true;
        }

        $held = $this->tenantAssignments($actor)->pluck('role')->all();

        if (in_array('tp-owner', $held, true)) {
            return true;
        }

        // A provider's own roles are handed out by its owners only.
        if ($this->catalogue->isCustom($role)) {
            return false;
        }

        return in_array('centre-manager', $held, true)
            && in_array($role, $this->catalogue->delegableByCentreManager(), true);
    }

    public function grantPlatformRole(User $user, string $role, ?User $by = null): PlatformRoleAssignment
    {
        if (! $this->catalogue->isPlatformRole($role)) {
            throw AccessError::wrongScope($role);
        }

        $assignment = $this->context->runAsPlatform(fn (): PlatformRoleAssignment => PlatformRoleAssignment::query()->firstOrCreate(
            ['user_id' => $user->id, 'role' => $role],
            ['granted_by' => $by?->id],
        ));

        $this->forget($user);

        return $assignment;
    }

    public function revokePlatformRole(User $user, string $role): void
    {
        $this->context->runAsPlatform(fn () => PlatformRoleAssignment::query()
            ->where('user_id', $user->id)->where('role', $role)->get()->each->delete());

        $this->forget($user);
    }

    public function forget(User $user): void
    {
        unset($this->platform[$user->id]);

        foreach (array_keys($this->tenant) as $key) {
            if (str_starts_with($key, $user->id.'|')) {
                unset($this->tenant[$key]);
            }
        }
    }

    /** Validate a role (and centre) for assignment in the current tenant. */
    public function guard(string $role, ?string $centreId): void
    {
        $this->guardTenantRole($role, $centreId);
    }

    private function guardTenantRole(string $role, ?string $centreId): void
    {
        if (! $this->catalogue->hasRole($role)) {
            throw AccessError::unknownRole($role);
        }

        $scope = $this->catalogue->scope($role);

        if ($scope === 'platform') {
            throw AccessError::wrongScope($role);
        }

        if ($scope === 'tenant' && $centreId !== null) {
            throw AccessError::centreNotAllowed($role);
        }

        // Foreign keys are not subject to row-level security, so check that the centre
        // belongs to the current provider explicitly.
        if ($centreId !== null && ! Centre::query()->whereKey($centreId)->exists()) {
            throw AccessError::unknownCentre();
        }
    }
}
