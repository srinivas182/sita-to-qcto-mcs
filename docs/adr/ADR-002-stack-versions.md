# ADR-002: Latest stable stack, pinned at kick-off

**Status:** Accepted (approved 22 Nov 2026)

## Context
The platform must stay modern for a decade and be easy to upgrade.

## Decision
Laravel 13 on PHP 8.3+ (CI on 8.4), PostgreSQL 16, Redis 7, React 19 + Vite + TanStack, Tailwind CSS v4 with shared tokens, Expo (latest SDK) for mobile. Versions are pinned in lock files and upgraded deliberately.

## Consequences
Small, regular upgrades instead of large migrations.
