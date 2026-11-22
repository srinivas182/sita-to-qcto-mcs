# ADR-009: Roles in a versioned catalogue; assignments as RLS-protected data

**Status:** Accepted for Sprint 1c, pending owner review. Supersedes ADR-006.

## Context
ADR-006 chose spatie/laravel-permission with teams = tenant. Building 1c showed
four conflicts with the rest of the architecture:

1. **Row-level security.** Spatie's team column is part of a composite primary key and
   cannot be NULL, so platform roles (Super Admin, regulators) need a fake "team".
   Those rows then fall outside RLS or need special-case policies.
2. **Centre scope.** The same role at two centres collides with Spatie's primary key.
   It needs a custom pivot, which defeats the point of the package.
3. **Long-lived workers.** Spatie keeps the current team id in a process-wide
   registrar and caches all role→permission mappings globally. Under Octane and
   queue workers, that is shared state across tenants.
4. **Speed.** Every check walks role→permission tables (cached) and model_has_roles.

## Decision
- **Roles and permissions are code:** `config/access.php` holds 17 roles, each with
  scope (platform / tenant / centre), MFA requirement and permissions. Changes are
  reviewed and versioned like any other code.
- **Assignments are data:**
  - `role_assignments (tenant_id, user_id, role, centre_id NULL = all centres)` has
    forced RLS.
  - `platform_role_assignments (user_id, role)` is readable only in the platform
    context.
- **`AccessService`** answers `can(user, permission, ?centre)` and `centresFor()`,
  memoised per request (scoped binding). `Gate::before` routes catalogue permissions
  to it, so controllers use the normal `Gate::authorize('learners.view', $centreId)`.
- **Delegation rules:**
  - Owners assign any provider role.
  - Centre managers assign only facilitator, assessor, moderator, site supervisor and
    learner.
  - Nobody changes their own roles.
  - A provider keeps at least one owner.
- **Support access:** staff in an approved window get a fixed read-only set
  (`support_permissions`).

## Consequences
- No third-party permission package in the request path. Checks are in-memory after
  one indexed query per request.
- Isolation of role data is guaranteed by the database, like every other tenant table.
- Custom per-provider roles (later sprint) become a `tenant_roles` table merged into
  the catalogue at runtime. The API shape (`GET /v1/roles`) does not change.
- spatie/laravel-permission was removed from composer.json.
