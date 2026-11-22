# Sprint 1 — Foundation & Platform Core (specification for approval)

**Status:** Draft for approval · **Branch:** `sprint-1/platform-core` · **Length:** 2 weeks (includes the remaining Sprint 0 technical foundation)
**Owner:** TYM × MCS Joint Venture · **Prepared by:** MCS

> Nothing in this sprint is coded until this specification and the decisions in §11 are approved.

---

## 1. Goal

Stand up the technical foundation and the platform's front door. By the end of the sprint:

- The monorepo, CI and local environment run with one command.
- A Super Admin can sign in, create a training provider (TP), place it in a cell and give it a subdomain or its own domain.
- TP staff and learners can sign in on the TP's own branded web address and in the mobile app, including offline sign-in for learners.
- Roles and permissions decide what every person sees, scoped to tenant and centre.
- Every write is audit-logged; tenant data is isolated at the application and database level.

**Demo at the end:** Super Admin creates "Training Young Minds", adds `learn.trainingyoungminds.co.za`, invites a centre manager; the centre manager signs in with a passkey on the branded page; a learner signs in on the phone app with a WhatsApp PIN, goes offline, closes and reopens the app and is still signed in.

---

## 2. What market leaders do — and where they fall short

| Area | Leaders | What they do well (we adopt) | Where they fall short | What we add (our edge) |
|---|---|---|---|---|
| Sign-in & identity | Clerk, Auth0 (Okta), WorkOS, Stytch | Passkeys, magic links/OTP, MFA, organisation switching, polished prebuilt sign-in | Priced per monthly active user — costly at millions of learners in ZAR; data hosted offshore (POPIA concern); no SA ID awareness; no offline sign-in | Same UX built in-house on Laravel, hosted in South Africa; **SA ID smart check** (checksum, date of birth, gender); **WhatsApp PIN with SMS fallback** and cost-aware routing; **offline sign-in** for learners (14 days, PIN or fingerprint) |
| Multi-tenant SaaS & custom domains | Shopify, Vercel, Webflow | Instant subdomain, custom domain with guided DNS check and automatic SSL | Generic set-up wizards; no notion of accredited sites | One-screen domain set-up with live DNS checker and auto SSL; **tenant → centre hierarchy** where each centre carries its own QCTO accreditation number |
| Roles & permissions | WorkOS FGA, Permit.io, Linear, Stripe teams | Clear roles, least privilege, permission previews | External services add latency and cost; generic roles | 17 ready-made roles from the journeys, scoped **per tenant and per centre**; "view as role" preview for TP owners |
| Admin consoles | Stripe Dashboard, Linear, Retool-style tools | Fast, keyboard-first (⌘K), clean tables, audit trails | Audit is often hidden or paid add-on; impersonation without consent | **Consent-based support access**: Super Admin can only enter a TP's space after the TP approves, with every action logged and visible to the TP |
| SA learner-management systems | Wemasoft, skillSYMS, SETA portals | QCTO/SETA exports, PoE handling | Desktop-first, dated look, provider-only, weak mobile and offline | Modern locked design (theme C), mobile-first learner app, every stakeholder connected |

---

## 3. Scope

### 3.1 Foundation (completes Sprint 0)
- Monorepo: `apps/api` (Laravel), `apps/web` (React), `apps/mobile` (Expo), `packages/shared`, `packages/ui-tokens`, `packages/api-client`, `infra`, `docs/adr`.
- Tooling: pnpm workspaces + Turborepo (JS), Composer (PHP); one command `make up` starts PostgreSQL, Redis, Mailpit, API, web.
- Design tokens package from the locked theme C (colours, type scale, spacing, radii) used by both web (Tailwind) and mobile (NativeWind).
- Architecture Decision Records (ADR-001 … ADR-008, see §11).
- Cell skeleton: configuration and code paths for "global layer" vs "cell" (single cell in this sprint, ready for more).

### 3.2 Backend (`apps/api`)
**Modules** (modular monolith, each with its own routes, services, policies, events, tests):
`Identity`, `Tenancy`, `Access`, `Audit`, `Platform` (health, settings).

**Data model (PostgreSQL)**

| Table | Purpose / key columns |
|---|---|
| `cells` | id, name, region, status, capacity_tenants |
| `tenants` | id (ULID), cell_id, legal_name, trading_name, status (pending/verified/active/suspended), package_code, branding (json: logo, colours), created_by |
| `tenant_domains` | tenant_id, hostname, type (subdomain/custom), verification_token, dns_status, ssl_status, verified_at, is_primary |
| `centres` | tenant_id, name, accreditation_number, accreditation_expires_on, status (full module in Sprint 2) |
| `users` | id (ULID), first_name, last_name, email (nullable), phone_e164 (nullable), sa_id_hash / passport_hash, id_type, dob, locale, status |
| `user_tenants` | user_id, tenant_id, centre_id (nullable), status, invited_by, joined_at |
| `roles`, `permissions`, `model_has_roles` … | Spatie Permission with **teams = tenant** (+ centre scope column) |
| `passkeys` | user_id, credential_id, public_key, sign_count, name, last_used_at |
| `mfa_factors` | user_id, type (totp), secret (encrypted), confirmed_at, recovery_codes (encrypted) |
| `otp_challenges` | phone/email, channel (whatsapp/sms/email), code_hash, attempts, expires_at |
| `devices` | user_id, platform, push_token, offline_key_fingerprint, last_seen_at, revoked_at |
| `consents` | user_id, tenant_id, purpose, version, granted_at, withdrawn_at (POPIA) |
| `support_access_grants` | tenant_id, granted_to (Super Admin user), reason, starts_at, ends_at, approved_by |
| `audit_logs` | id, tenant_id, actor_id, action, subject_type, subject_id, changes (json), ip, user_agent, created_at (append-only, partitioned by month) |

- **Tenant isolation:** every tenant-owned table carries `tenant_id`; a global query scope + **PostgreSQL row-level security** policies keyed on `app.tenant_id` set per request/job. A test suite proves cross-tenant reads/writes fail.
- **Identity numbers:** SA ID and passport stored as salted hashes for lookup plus encrypted value; never logged.

**API v1 (versioned, OpenAPI documented, generates `packages/api-client`)**

| Endpoint | What it does |
|---|---|
| `POST /v1/auth/otp/request` · `POST /v1/auth/otp/verify` | Learner sign-in by phone: WhatsApp first, SMS fallback; rate-limited |
| `POST /v1/auth/login` | Staff email + password (then passkey or MFA if enrolled) |
| `POST /v1/auth/passkeys/options` · `/register` · `/login` | WebAuthn passkeys |
| `POST /v1/auth/mfa/totp/enable` · `/confirm` · `/challenge` | Authenticator-app MFA + recovery codes |
| `POST /v1/auth/logout` · `GET /v1/me` | Session end; current user, roles, tenant, branding |
| `POST /v1/auth/devices` · `DELETE /v1/auth/devices/{id}` | Register a mobile device for offline sign-in; revoke (remote wipe) |
| `GET/POST/PATCH /v1/admin/tenants` · `POST /v1/admin/tenants/{id}/approve` | Super Admin tenant management |
| `POST /v1/tenants/{id}/domains` · `POST …/verify` · `GET …/status` | Add domain, live DNS + SSL status |
| `GET /v1/admin/cells` | Cells and their load |
| `POST /v1/tenants/{id}/invitations` · `POST /v1/invitations/{token}/accept` | Invite staff with a role and centre |
| `GET /v1/roles` · `PUT /v1/users/{id}/roles` | Roles and assignment |
| `POST /v1/support-access` · `POST …/{id}/approve` | Consent-based support access |
| `GET /v1/audit-logs` | Filterable audit trail (TP sees its own; Super Admin sees all) |
| `GET /health` · `GET /ready` | Health and readiness for load balancers |

- **Tenant resolution:** from the request host (`learn.trainingyoungminds.co.za` → tenant) via a cached lookup in Redis; the mobile app sends `X-Tenant` after the TP code/QR step.
- **Sessions:** Sanctum — secure HTTP-only cookies for the web SPA; short-lived access + refresh tokens for mobile, bound to the device.

### 3.3 Web (`apps/web`)
- React + TypeScript + Vite; TanStack Router and TanStack Query; Tailwind CSS with the shared tokens; accessible headless components (Radix-based).
- **App shell** exactly as the mock-ups: left sidebar (full → icon rail → drawer), top bar with ⌘K command palette, role switch for users with several roles.
- **Sign-in screens:** tenant-branded (logo, colours, domain); staff sign-in with passkey-first, password + MFA fallback; learner sign-in by SA ID/passport + WhatsApp/SMS PIN; forgotten password; invitation acceptance.
- **Super Admin console:** overview (live counts), tenants (list, detail, approve, suspend, place in cell), domains (status), cells, users & roles, audit log, support-access approvals.
- **TP owner:** brand & domain screen (live DNS checker), users & roles, audit log.
- Languages scaffolded (English first; isiZulu, Sesotho, isiXhosa, Afrikaans, Setswana keys ready).

### 3.4 Mobile (`apps/mobile`)
- Expo (latest SDK) + Expo Router + TypeScript; NativeWind with shared tokens; light theme default, dark mode setting.
- Screens: welcome & language, TP code / QR scan, phone sign-in with PIN (WhatsApp/SMS), biometric/PIN set-up, home shell with tab bar, settings (language, dark mode, devices, sign out).
- **Offline foundation:** encrypted local database (SQLite with SQLCipher), secure key storage (Keychain/Keystore), an **outbox sync queue** (idempotency keys, retries with back-off, conflict rules), connectivity banner. Offline sign-in valid for 14 days; remote wipe on device revoke.

### 3.5 Quality, CI and environments
- **Local checks before push:** Pint, Larastan (level 8), ESLint, TypeScript, Prettier.
- **GitHub Actions** (minutes-efficient, per agreed CI process):
  - Draft PR → quick checks only (lint + static + type check) ≈ 2–3 min.
  - Ready-for-review PR → full suite once: Pest (PHP), Vitest (web), Jest (mobile), Playwright smoke (sign-in flows), in parallel jobs; target ≤ 7 min, each job capped at 12 min.
  - Path filters (docs-only / web-only / mobile-only skip unaffected jobs); superseded runs cancelled; no repeat run on `main` after merge.
- Mobile builds via **EAS Build** (internal preview build for testing on phones; store builds later).

---

## 4. Performance budgets ("loads in milliseconds")

| Measure | Target |
|---|---|
| API response time (p95, server) | < 150 ms for reads, < 250 ms for writes |
| Web first load (4G, mid-range phone) | Largest paint < 1.8 s; returning visit < 0.8 s (cached shell) |
| Web interaction response (INP) | < 150 ms; page-to-page navigation feels instant (< 100 ms with prefetch) |
| Web initial JavaScript | < 160 KB compressed; routes code-split |
| Mobile cold start (mid-range Android) | < 2 s to home; warm start < 0.6 s |
| Tenant resolution | < 2 ms (Redis cache) |

Budgets are checked in CI (bundle size check) and with a k6 load test on the sign-in and `GET /v1/me` paths before the sprint closes.

## 5. Security & POPIA
Argon2id passwords; passkeys preferred for staff; MFA mandatory for Super Admin and TP owners; rate limits on every auth endpoint; lockout with back-off; CSRF on web; strict CORS per tenant domain; security headers (CSP, HSTS); encrypted identity numbers and MFA secrets; append-only audit log; consent records; no identity numbers or PINs in logs; secrets via environment, never in the repo.

## 6. Accessibility
WCAG 2.2 AA: contrast (theme C checked), keyboard navigation, focus states, screen-reader labels, 44 px tap targets on mobile.

## 7. Out of scope (later sprints)
Packages & billing (Sprint 2), centres full module, qualification catalogue, enrolment, gateways UI and real WhatsApp/SMS providers (Sprint 1 uses a provider interface with a log driver and one SMS sandbox), AI, simulators.

## 8. Definition of done
- Every item in §3 built (no narrower versions), with tests; CI green; merged to `main`.
- Demo script in §1 runs end to end on web and on a phone (Expo preview build).
- Cross-tenant isolation tests pass; performance budgets in §4 met.
- PRD checklist report: each item and each agreed addition marked done, with links to code and tests.

## 9. Risks
| Risk | Mitigation |
|---|---|
| Custom-domain SSL at scale | Cloudflare for SaaS custom hostnames (ADR-005); local dev uses mkcert |
| WhatsApp template approval takes time | SMS fallback and log driver; templates submitted early |
| Passkey support on older Android | Password + MFA fallback always available |
| Two-week sprint is full | Foundation work front-loaded in week 1; Super Admin screens in week 2 |

## 10. Mobile build & deploy recommendation
**Expo with EAS (Build, Submit, Update).** One codebase for Android and iOS; cloud builds (no Mac needed for iOS builds); one-command submission to **TestFlight** and **Google Play**; over-the-air updates for urgent fixes without store review; white-label builds per TP from one project. When you have the Apple Developer and Google Play accounts, we connect them to EAS and ship. Alternative (own build machines with Fastlane) is cheaper per build but needs a Mac and more maintenance — not recommended now.

## 11. Decisions to approve
1. **ADR-001 Monorepo:** pnpm + Turborepo + Composer in one repo; Unity simulators in a separate repo later.
2. **ADR-002 Stack versions:** latest stable PHP and Laravel, React 19 + Vite + TanStack, Tailwind with shared tokens, Expo latest SDK — pinned at kick-off.
3. **ADR-003 Database:** PostgreSQL with row-level security (not MySQL).
4. **ADR-004 Auth built in-house** on Laravel (Sanctum + WebAuthn + TOTP) instead of Clerk/Auth0 — for POPIA, SA hosting and cost at scale.
5. **ADR-005 Custom domains:** Cloudflare for SaaS custom hostnames (auto SSL, firewall, SA edge).
6. **ADR-006 Permissions:** Spatie Permission with tenant teams + centre scope, wrapped in our policies.
7. **ADR-007 Admin console in React** (not Filament/Nova) so every screen follows the locked design.
8. **ADR-008 Mobile:** Expo + EAS, SQLCipher-encrypted SQLite with an outbox sync queue.
9. **Learner sign-in identifier:** SA ID or passport number + phone PIN (WhatsApp first). Email optional for learners.

Reply "approved" (or list changes) and Sprint 1 starts immediately.
