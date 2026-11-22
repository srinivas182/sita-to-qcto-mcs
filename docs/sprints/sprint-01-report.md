# Sprint 1 — Platform Core: close report

**Status:** Build complete. All five sub-sprints merged with CI green. Two items wait on
accounts or people outside the code (marked *external*).
**Pull requests:** #1 foundation · #2 tenancy & RLS · #3 identity & access · #4 web ·
#5 mobile · #6 close (this report).

## 1. Demo script (spec §1)

| Step | Result | Evidence |
|---|---|---|
| Super Admin creates "Training Young Minds" and gives it a domain | ✅ | `AdminConsoleTest::onboards a training provider end to end`; CI `e2e` (approve flow) |
| Adds `learn.trainingyoungminds.co.za` with live DNS check and auto SSL | ✅ | `DomainServiceTest`, `CloudflareCustomHostnameProviderTest`; web DNS checker |
| Invites a centre manager | ✅ | `InvitationTest` |
| Centre manager signs in with a passkey on the branded page | ✅ API + web; browser passkey needs HTTPS | `PasskeyTest` (real WebAuthn verification, software authenticator) |
| Learner signs in on the phone with a WhatsApp PIN, goes offline, reopens, still signed in | ✅ in the app build; *external*: real phone and real WhatsApp | `OtpSignInTest`; mobile `session.test.ts`; phone-size walkthrough (`apps/mobile/e2e/preview.py`). Needs an EAS preview build (Expo, Apple, Google accounts) and a WhatsApp Business account |

## 2. Scope checklist (spec §3)

### 3.1 Foundation
| Item | Status | Where |
|---|---|---|
| Monorepo (api, web, mobile, packages, infra, ADRs) | ✅ | `apps/*`, `packages/*`, `infra/`, `docs/adr/` |
| One-command local start | ✅ | `make up`, `make demo`, `make dev` |
| Design tokens shared by web and mobile | ✅ | `packages/ui-tokens` (tests keep web theme, NativeWind preset and WCAG contrast in sync) |
| ADR-001…008 (+ ADR-009) | ✅ | `docs/adr/` — **ADR-009 replaces ADR-006 and needs your approval** |
| Cell skeleton | ✅ | `cells` table, `CellPlacement`, `PLATFORM_CELL`, admin cells screen |

### 3.2 Backend
| Item | Status | Where |
|---|---|---|
| Modules: Identity, Tenancy, Access, Audit, Platform | ✅ | `apps/api/modules/*` |
| All tables in the data model | ✅ | migrations in each module; Spatie tables replaced by `role_assignments` / `platform_role_assignments` (ADR-009) |
| Tenant isolation with PostgreSQL RLS, proven by tests | ✅ | `RowLevelSecurityTest` (raw-SQL attacks; CI fails if any `tenant_id` table lacks forced RLS) |
| Identity numbers hashed + encrypted, never logged | ✅ | `User::setIdentityNumber`, `OtpSignInTest::never stores the PIN or the identity number in the clear` |
| Every API endpoint in the table | ✅ | 64 routes; `packages/api-client/openapi.json` |
| OpenAPI documented, generates `packages/api-client` | ✅ | Scramble → `make api-client` |
| Tenant resolution by host (Redis) and `X-Tenant` for the app | ✅ | `TenantResolutionTest`; 0.1 ms warm |
| Sessions: cookie (web), access + refresh tokens bound to the device (mobile) | ✅ | `DeviceTokenTest`; refresh signed by the device key |
| Real WhatsApp/SMS providers; "one SMS sandbox" | ⏭ Sprint 2 | Provider interface, fallback chain and log driver are done. Meta requires an approved authentication template (copy-code button, no links) |

### 3.3 Web
| Item | Status | Where |
|---|---|---|
| React + Vite + TanStack + Tailwind tokens + Radix | ✅ | `apps/web` |
| Shell: sidebar full → rail → drawer, ⌘K, role chip | ✅ | `shell/` |
| Branded sign-in: passkey first, password + MFA, learner PIN, forgotten password, invitation | ✅ | `features/auth` |
| Super Admin console (overview, tenants, domains, cells, users & roles, audit, support access) | ✅ | `features/admin`, `features/support`, `features/audit` |
| TP owner: brand & domain (live DNS), users & roles, audit | ✅ | `features/provider` |
| Languages scaffolded | ✅ | `lib/i18n` (en + zu, xh, st, tn, af starters; *external*: first-language review) |

### 3.4 Mobile
| Item | Status | Where |
|---|---|---|
| Expo + Router + NativeWind; light default, dark setting | ✅ | `apps/mobile` |
| Welcome & language, TP code / QR, phone PIN, biometric / PIN, tabs, settings | ✅ | `app/` |
| Encrypted SQLite, secure key storage, outbox (idempotency, retries, conflicts), connectivity banner | ✅ | `src/core/outbox.ts` (+ tests), `src/lib/db.ts`, API `Idempotency` middleware |
| 14-day offline sign-in, remote wipe | ✅ | `src/core/session.ts` (+ tests), `DeviceTokenTest` |
| EAS preview build | ⏳ *external* | `eas.json` ready; needs the Expo account (then `eas build --profile preview`) |

### 3.5 Quality, CI and environments
| Item | Status | Where |
|---|---|---|
| Local checks: Pint, Larastan L8, ESLint, TypeScript, Prettier | ✅ | `make check` |
| Draft PR = quick checks; ready PR = full suite once; path filters; cancel superseded; 12-min job cap | ✅ | `.github/workflows/ci.yml` |
| Pest, Vitest, browser smoke of sign-in flows | ✅ | 179 PHP tests · 31 JS tests · `e2e` job (Chrome, real API) + axe accessibility audit |
| Mobile tests | ✅ | Vitest for the platform-independent core; Metro/Hermes bundle in CI (`mobile` job) |
| Full CI run time | ✅ | ≈ 1.5 min wall clock (target ≤ 7 min) |

## 3. Performance budgets (spec §4)

| Measure | Target | Measured | Notes |
|---|---|---|---|
| API reads p95 (server) | < 150 ms | **13 ms** for `GET /v1/me` with the app already running (as under Octane); **47–50 ms** on the PHP dev server, which boots Laravel on every request | 2-core sandbox, concurrency matched to cores. At 10 concurrent clients on 2 cores the dev server queues (p95 296 ms); that is a hardware limit, not an app cost |
| API writes p95 | < 250 ms | **≈ 165–220 ms** for sign-in (Argon2id 64 MiB × 2 plus token issue) | Argon2id tuned from 4 to 2 passes. That is still above the OWASP baseline; older hashes upgrade on login |
| Tenant resolution | < 2 ms | **0.1 ms** warm (Redis) | |
| Web initial JS | < 160 KB gz | **118 KB** | Enforced in CI |
| Web first paint / navigation | < 1.8 s / instant | Sign-in interactive in ≈ 0.2–0.3 s; screen changes 70–300 ms including the API | Local; real 4G figures come from staging |
| Mobile cold start | < 2 s | ⏳ *external* | Measured on a mid-range Android once the preview build exists |

The k6 script for staging (Octane, real hardware) is ready: `infra/load/k6-sprint1.js`.
It runs at sprint 33 (load tests at target scale) and whenever staging changes.

## 4. Security & POPIA (spec §5)
All delivered:
- Argon2id; passkeys first.
- MFA mandatory for platform staff and owners.
- Rate limits on every sign-in path; lock-out with back-off.
- CSRF; CORS closed on all paths (test added).
- CSP, HSTS and frame-deny headers.
- Encrypted identity numbers and MFA secrets; recovery codes stored only as hashes.
- Append-only, partitioned audit log; consent records.
- No identity numbers or PINs in logs (PINs are logged only on a local machine with
  `OTP_LOG_CODES=true`).
- Secrets only in the environment.

## 5. Accessibility (spec §6)
- An axe-core audit (WCAG 2.0/2.1/2.2 A + AA) of sign-in, MFA, overview, users,
  brand, security and audit shows **no violations**. It runs in CI.
- The audit found one issue, now fixed: "faint" text was 3.2:1. It is now 4.85:1,
  and a token test keeps it ≥ 4.5:1.
- Phone tap targets are 48–64 px.
- Keyboard navigation and focus rings work across the web app.
- Fields and buttons carry screen-reader labels on mobile.

## 6. Changes from the spec (for your approval)
1. **ADR-009 instead of ADR-006:** the role catalogue is in code; assignments are
   RLS-protected data. Spatie Permission was removed. Reasons: RLS, centre scope, and
   shared state in long-lived workers.
2. **`Idempotency-Key` on all writes** (added): retries from the phone can never
   double-apply.
3. **Delivered early from Sprint 2:** custom domains with auto SSL, branding, and
   tenant selection by code/QR in the app.

## 7. Carried into Sprint 2
- Real WhatsApp (Meta authentication template) and SMS providers; Super Admin
  gateway screens.
- Uploads (logos, documents) with virus scanning; centre names in role lists (full
  centre module).
- Per-provider custom roles (ADR-009 extension).

## 8. Needed from you / TYM
- Approve ADR-009 and this report.
- When ready: an Expo account, an Apple Developer account and a Google Play
  Console. These give the first phone build and the cold-start measurement.
- A WhatsApp Business account (Meta Business verification) and an SMS provider
  account for real PINs in Sprint 2.
- First-language reviewers for the five SA language starters.
