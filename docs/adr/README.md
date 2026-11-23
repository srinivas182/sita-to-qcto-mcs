# Architecture decision records

| ADR | Decision | Status |
|---|---|---|
| [ADR-001](ADR-001-monorepo.md) | Monorepo with pnpm, Turborepo and Composer | Accepted |
| [ADR-002](ADR-002-stack-versions.md) | Latest stable stack, pinned at kick-off | Accepted |
| [ADR-003](ADR-003-postgresql-rls.md) | PostgreSQL with row-level security for tenant isolation | Accepted |
| [ADR-004](ADR-004-auth-in-house.md) | Authentication built on Laravel, not an external identity service | Accepted |
| [ADR-005](ADR-005-custom-domains.md) | Cloudflare for SaaS for custom domains | Accepted |
| [ADR-006](ADR-006-permissions.md) | Spatie Permission with tenant teams and centre scope | Superseded by ADR-009 |
| [ADR-007](ADR-007-react-admin.md) | Admin console in React, not Filament or Nova | Accepted |
| [ADR-008](ADR-008-mobile-expo.md) | Expo with EAS; encrypted SQLite with an outbox sync queue | Accepted |
| [ADR-009](ADR-009-access-catalogue.md) | Roles in a versioned catalogue; assignments as RLS-protected data | Accepted (proposed in 1c; review) |
- [ADR-013 Provider onboarding](ADR-013-onboarding.md)
- [ADR-014 Shared qualification catalogue](ADR-014-qualification-catalogue.md)
- [ADR-015 Staff credentials, provider roles and personal data](ADR-015-people-credentials-roles.md)
- [ADR-016 Host employers, workplace approvals and capacity](ADR-016-employers-workplaces.md)
- [ADR-017 Agreements and electronic signatures](ADR-017-agreements-esignature.md)
- [ADR-018 Cohorts and the readiness check](ADR-018-cohorts-readiness.md)
- [ADR-019 Applicants, intakes and applications](ADR-019-applicants.md)
- [ADR-020 Learner documents and their checks](ADR-020-learner-documents.md)
