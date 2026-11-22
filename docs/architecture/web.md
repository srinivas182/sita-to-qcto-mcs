# Web app

Sub-sprint 1d · `apps/web`, `packages/api-client`

## Stack
- React 19, Vite 7, TypeScript (strict).
- TanStack Router (code-based routes, preload on hover) and TanStack Query.
- Tailwind CSS v4 with the locked theme C tokens from `@skills/ui-tokens`.
- Radix primitives for dialog and menu, Lucide icons and Inter (self-hosted variable font).
- The typed API client is generated from the API's OpenAPI document (Scramble):
  `make api-client`.

## One app, every host
The same build is served on every host. It reads `GET /v1/branding` first:
- **A provider host** (`tym.<base>`, `learn.trainingyoungminds.co.za`) shows that
  provider's name, logo and colours, and the provider console.
- **A platform host** (`admin.<base>`) shows the Super Admin console.

Same-origin cookie sessions and CSRF make sign-in work on custom domains with no CORS.

## Screens (Sprint 1)
- **Sign-in:**
  - Passkey first, with browser autofill (conditional mediation).
  - Email + password, then two-step code.
  - Learner phone PIN, with WebOTP auto-fill on Android.
  - Forgotten and reset password; invitation acceptance.
- **Shell:**
  - Light sidebar: full, then icon rail, then a drawer on phones.
  - ⌘K command palette.
  - Role chip and account menu.
  - MFA-required and support-access banners.
- **Super Admin:**
  - Overview with live counts.
  - Training providers: list, create, detail, verify / approve / suspend / reactivate,
    domains with live DNS checker, invite owner.
  - Cells (load bars), support-access requests, platform staff, audit log.
- **Provider:**
  - Overview with set-up checklist.
  - Users & roles: invite by email or WhatsApp; edit roles.
  - Brand & domain with a live sign-in preview.
  - Support-access approvals, audit log.
- **Everyone:** Security — passkeys, authenticator app with QR and recovery codes,
  signed-in phones with remote wipe.
- **Languages:** English, with isiZulu, isiXhosa, Sesotho, Setswana and Afrikaans
  scaffolded (lazy-loaded; missing keys fall back to English).

## Performance (measured)
- **Initial JavaScript:** 118 KB gzip (budget 160 KB, enforced in CI by
  `pnpm --filter @skills/web run size`).
- **Code splitting:** every screen is split; the shell loads after sign-in. The QR
  library loads only on the Security screen.
- **Smoke test** (local PHP dev server):
  - The sign-in page is interactive in about 200–290 ms.
  - Moving between screens takes about 70–300 ms, including the API call.

## Tests
- Vitest + Testing Library: client, i18n, WebAuthn encoding, navigation permissions,
  learner PIN flow.
- `apps/web/e2e/smoke.py` (CI job `e2e`) drives Chrome against the real API with demo
  data:
  - Owner and Super Admin sign-in with MFA.
  - Every console screen, ⌘K and domain connection.
  - Approving a provider.
  - Phone sign-in, PIN request and the drawer.
