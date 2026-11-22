# ADR-003: PostgreSQL with row-level security for tenant isolation

**Status:** Accepted (approved 22 Nov 2026)

## Context
Training providers share a database inside a cell; one coding mistake must never expose another provider's data.

## Decision
Every tenant-owned table has `tenant_id`. Application scopes filter by tenant, and PostgreSQL row-level security policies (forced, also for the table owner) deny rows of other tenants. Platform-level work runs in an explicit, audited platform context. The application connects as a non-superuser role.

## Consequences
Two independent layers of isolation; tests prove cross-tenant access fails.
