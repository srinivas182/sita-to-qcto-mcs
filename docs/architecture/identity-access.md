# Identity and access

Sub-sprint 1c · modules `Identity`, `Access` · ADR-004, ADR-009

## People
- `users` is global. One person can train with several providers over a lifetime.
- A provider sees a person only while they are a member (`user_tenants`). This is
  enforced by an RLS policy on `users`.
- SA ID / passport numbers are stored as an HMAC lookup hash (`IDENTITY_PEPPER`) plus
  the encrypted value. Screens show `800101•••••87`. The numbers are never logged.
- SA ID numbers are validated by date of birth, citizenship digit and Luhn check.
  Phone numbers are normalised to E.164 (SA local formats understood).

## Sign-in methods

| Who | Method | Notes |
|---|---|---|
| Learners | Phone, SA ID or passport → 6-digit PIN by **WhatsApp, SMS fallback** | Same response whether or not the person exists; 5 tries; 5 min expiry; 30 s resend; 5/hour per identifier |
| Staff | **Passkey** (WebAuthn, discoverable, user verification) | No attestation stored; RP = the host they sign in on |
| Staff | Email + password (Argon2id) → **authenticator code** if set up | Lock-out 1 / 5 / 15 / 60 min; recovery codes (10, single use, stored hashed) |
| Everyone | Invitation link → join | Existing people are linked, not duplicated |

- MFA is mandatory for Super Admin, the JV desk, regulators and TP owners. Until they
  set up an authenticator or passkey, only `/me`, MFA set-up and sign-out work
  (`mfa_setup_required`).
- Where people can sign in:
  - Provider hosts: active members only.
  - Platform hosts: platform staff only.
  - Platform staff enter a provider only through an approved **support-access** window.

## Sessions
- **Web:** cookie session plus CSRF. A request is first-party when its Origin or
  Referer equals the host, so no CORS or stateful allow-list is needed for custom
  domains.
- **App:**
  - Access token: 15 min, tenant-bound (RLS) and device-bound.
  - Refresh token: rotating and single use, 30 days. Every refresh must carry an
    **Ed25519 signature from the device key** (Keychain/Keystore) within ±5 min.
    Replaying a spent refresh token revokes the device.
  - Offline sign-in is allowed until `offline_until` (14 days, renewed on every refresh).
  - Revoking a device ends its sessions; the app wipes local data on `device_revoked`.

## Access
- See ADR-009. `Gate::authorize('<permission>', $centreId)` everywhere.
- `GET /v1/me` returns the person, provider, roles, effective permissions, security
  state and any support window. One call powers the shell.

## Support access (consent-based)
1. Staff: `POST /v1/admin/support-access {tenant_id, reason, minutes ≤ 240}` → pending.
2. Owner: `POST /v1/support-access/{id}/approve` (or deny). The window starts.
3. Staff: call provider endpoints on the admin host with `X-Tenant`. Access is
   read-only (`support_permissions`). Each visit is logged in the provider's own audit
   trail (`support_access.used`, tagged with the grant).
4. The owner can revoke at any time; windows expire on their own.

## Endpoints (v1)
- **Public:**
  - `auth/otp/request|verify`
  - `auth/login`
  - `auth/password/forgot|reset`
  - `auth/mfa/totp/challenge`
  - `auth/passkeys/login/options|login`
  - `auth/token/refresh`
  - `branding`
  - `invitations/{token}` (and `/accept`)
- **Signed in:**
  - `me`
  - `auth/logout`
  - `auth/mfa/*`
  - `auth/passkeys*`
  - `auth/devices*`
  - `me/consents*`
- **Provider (MFA enforced):**
  - `tenant`
  - `tenant/branding`
  - `tenants/{id}/domains*`
  - `roles`
  - `users`
  - `users/{id}/roles`
  - `invitations`
  - `tenants/{id}/invitations`
  - `support-access*`
  - `audit-logs`
- **Super Admin (`/v1/admin`, platform host):**
  - `overview`
  - `tenants*` (approve, verify, suspend, reactivate, cell, invitations, domains)
  - `cells`
  - `audit-logs`
  - `support-access`
  - `staff`

## Engineering rules learnt in 1c
- **Controllers must not take request-scoped services in their constructor.** Laravel
  caches controller instances on routes, in tests and in long-lived workers. Resolve
  per call (see the private getters).
- **Writes to global identity rows** (users) from a platform host run in the platform
  context. Platform-level audit entries are written in the platform context by
  `AuditLogger`.
