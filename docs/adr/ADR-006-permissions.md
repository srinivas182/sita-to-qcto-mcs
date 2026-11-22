# ADR-006: Spatie Permission with tenant teams and centre scope

**Status:** Superseded by [ADR-009](ADR-009-access-catalogue.md) (22 Nov 2026, during Sprint 1c)

## Context
Seventeen roles, scoped to a provider and sometimes to one centre.

## Decision
spatie/laravel-permission with teams = tenant, plus a centre scope on memberships; all checks go through Laravel policies so rules live in one place.

## Consequences
Well-known package; no external permission service in the request path.
