# Mobile app

Sub-sprint 1e · `apps/mobile` · ADR-008

## Stack
- Expo SDK 58 (React Native 0.88, New Architecture, Hermes) with Expo Router.
- NativeWind, with theme C from `@skills/ui-tokens` (a preset kept in sync by tests)
  and the Sora font.
- TanStack Query and the same generated API client as the web app.

## One app for every provider
At first launch the person picks a language and enters their training provider's code
or scans its QR code. The code accepts `tym`, `skills://tenant/tym` or `…?tp=tym`. The
app then shows that provider's name and colour and sends `X-Tenant` with every call.
"Not your provider? Change" wipes local data and starts again.

## Security on the phone

| Concern | How |
|---|---|
| Sign-in | Phone / SA ID / passport → PIN by WhatsApp or SMS (iOS and Android autofill the code) |
| Device binding | Ed25519 key generated on the phone and stored in Keychain/Keystore; the server stores only the public key |
| Tokens | Access token 15 min. Refresh token rotates and is single use; every refresh is signed by the device key. A replayed token signs the device out |
| App lock | Face ID / fingerprint, or a 6-digit app PIN (PBKDF2-SHA256, 60k rounds; weak PINs refused; 5 wrong tries = sign out and wipe). Re-locks after 5 min in the background |
| Data at rest | SQLite encrypted with SQLCipher; the key is random and kept in Keychain/Keystore |
| Offline | Opens offline for 14 days after the last refresh, then asks to go online |
| Remote wipe | Device revoked (web console or another phone) → next contact returns `device_revoked` → database and keys deleted |

## Offline outbox
Every change is written locally first and sent when online. Each item carries:
- an **Idempotency-Key**, so the API replays the first response for a retry instead
  of acting twice (see `Idempotency` middleware);
- a **stream**, so dependent changes stay in order.

Retries back off exponentially with jitter, from 5 s up to 15 min. A 409 or 422 is a
conflict: the server wins, and the item is parked on the home screen to retry or
discard. Sync runs:
- when the connection returns;
- when the app comes back to the foreground;
- every 60 s while the app is open;
- on pull-to-refresh.

## Screens (Sprint 1)
- **Getting started:** Welcome and language, provider code or QR, then phone PIN
  sign-in.
- **Security:** app-lock set-up (biometrics or PIN), unlock.
- **Tabs:**
  - Home: provider, greeting, sync status, parked changes.
  - Learn: filled in Sprint 3+.
  - Settings: language, light/dark/system, signed-in phones with sign-out, change
    provider, sign out.
- **Always:** offline banner on every screen.

## Builds and release (EAS)
`eas.json` profiles:
- **development:** dev client.
- **preview:** internal build for test phones; Android APK, iOS ad hoc.
- **production:** store builds, auto-increment.

`API_BASE_URL` is set per profile.

Once the accounts exist:
1. `npm i -g eas-cli && eas login` (Expo account), then `cd apps/mobile && eas init`.
   This writes the EAS project id; set it as `EAS_PROJECT_ID`.
2. `eas build --profile preview --platform all`. Testers install from the link or QR
   (iOS devices must be registered with `eas device:create`).
3. Stores:
   - **iOS:** `eas build --profile production --platform ios`, then
     `eas submit --platform ios`. Needs the App Store Connect app id in `eas.json`;
     TestFlight comes first.
   - **Android:** `eas build --profile production --platform android`, then
     `eas submit --platform android`. Needs a Play Console service-account key;
     builds go to the internal track first.
4. Over-the-air fixes: `eas update --channel preview|production` for JavaScript-only
   changes.

## Checks
- **CI job `mobile`:** type-check, unit tests and a full Hermes bundle for Android.
- **Unit tests:**
  - Outbox ordering, retries, conflicts and single-flight runs.
  - Session refresh: single flight, revoke → wipe, offline window.
  - Device signatures, verified with standard Ed25519 (the same as libsodium on the
    server).
  - App PIN.
- **`e2e/preview.py`:** phone-sized walkthrough of the web preview (design review).
  Real-device testing happens on the EAS preview build.
