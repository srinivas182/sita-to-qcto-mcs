# ADR-001: Monorepo with pnpm, Turborepo and Composer

**Status:** Accepted (approved 22 Nov 2026)

## Context
Web, mobile and API change together for most features; shared types, validation and design tokens must not drift.

## Decision
One repository: `apps/api` (Laravel, Composer), `apps/web` and `apps/mobile` (pnpm workspaces, Turborepo), `packages/*` for shared code. Unity simulators live in a separate repository and report through the API.

## Consequences
One pull request covers a whole feature; CI builds only what changed. Unity's large binary assets stay out of everyone's builds.
