# QCTO Skills Ecosystem (working title)

A multi-tenant SaaS platform for South Africa's occupational training journey under the QCTO — from enrolment and workplace learning to EISA, certification, jobs and enterprise. Owned and operated by the **TYM × MCS Joint Venture** (Training Young Minds and Mayura Consultancy Services).

## Status

**Sprint 0 — Foundation & mock-ups.** Clickable web and mobile mock-ups for all 17 roles are in [`design/mockups`](design/mockups/README.md).

## Planned structure (monorepo)

```
apps/
  api/        Laravel (latest) — versioned REST API, queues, rules engine
  web/        React + TypeScript — all web portals
  mobile/     React Native + Expo — Android and iOS, offline-first
packages/
  shared/     API types, validation rules, translations
  api-client/ generated from the API specification
  ui-tokens/  colours, type and spacing shared by web and mobile
infra/        Terraform, Docker, cell templates
docs/         architecture decisions and API docs
design/       mock-ups and design assets
```

3D/VR simulators (Unity) live in a separate repository and report results through the API.

## Architecture in brief

- Cell-based multi-tenancy: a shared global layer (identity, tenant routing, billing, shared registries) and isolated cells, each a full stack serving a group of training providers; dedicated cells for large clients.
- Modular monolith in Laravel with domain events, feature flags per tenant and package, and cell-by-cell rollout with automatic rollback.
- Hosted in South Africa; POPIA by design.
