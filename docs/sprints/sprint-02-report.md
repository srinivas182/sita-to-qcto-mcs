# Sprint 2 — Provider onboarding, billing and centres: close report

**Status:** Build complete. Parts 2a, 2b, 2b-ii, 2c and 2d merged with CI green. The
close pull request adds the items found missing in the close review (§2, *close*).
Items that wait on accounts or people outside the code are marked *external*.
**Pull requests:** #7 settings & files · #8 billing · #9 five more gateways · #10 messaging ·
#11 onboarding & centres · #12 close (this report).

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. A new provider applies on the public page and uploads its QCTO letter | ✅ | `ApplicationTest` (start → verify → upload → submit); browser walk-through of all 4 steps in Chrome; a11y audit of `/pricing`, `/apply`, `/apply/resume` |
| 2. Super Admin verifies it from a checklist | ✅ | Approve is blocked until all 4 checks are ticked; one transaction creates tenant, first centre, trial and owner invitation |
| 3. Picks an (example) package and pays by card or Instant EFT | ✅ code, sandbox-tested with recorded responses; *external*: live sandbox keys | 7 gateways (Paystack, PayFast, Peach, Ozow, Yoco, Stitch, iKhokha) with signed, idempotent webhooks |
| 4. Receives a VAT tax invoice | ✅ | SARS tax-invoice fields, gap-free numbers, PDF in 80 ms, credit notes |
| 5. Adds two centres with scope and expiry | ✅ | `/centres`; 90/60/30/expired alerts, once per threshold, reset on renewal; map pin |
| 6. Goes live on its own domain | ✅ (Sprint 1) | Custom domains with auto SSL |
| 7. Learner signs in with a real WhatsApp PIN, one tap on Android | ✅ code; *external*: Meta-approved template and a phone build | WhatsApp one-tap and SMS Retriever autofill (own Android module, compiled in CI), paste-from-WhatsApp fallback, iOS keyboard code |

The definition of done asks for the demo **in staging** with gateway sandboxes and a real
WhatsApp test number. That needs the accounts in §8; everything in the code path is built
and tested against the providers' documented requests and responses.

## 2. Scope checklist (spec §2)

### Backend
| Item | Status | Where |
|---|---|---|
| Onboarding: public application, CIPC/QCTO/VAT checks, letter upload, POPIA consent | ✅ | `modules/Onboarding`, ADR-013 |
| Review queue: checklist, approve / ask for info / decline with reasons | ✅ | `/v1/admin/applications` |
| Decisions sent by email **and WhatsApp/SMS** | ✅ *close* | `MessageService::notify` (WhatsApp utility template, else SMS) |
| On approval: tenant, subdomain, owner invited | ✅ | `ApplicationService::approve` |
| Accreditation expiry watcher (provider and centre level) | ✅ | `centres:accreditation-alerts`, daily |
| Packages (example prices), trials, annual discount | ✅ | `modules/Billing` |
| Add-ons (AI credits, simulator access) | ⏭ moved | Neither product exists yet; see §7 |
| Subscription states trial → active → past due → read-only → suspended; proration | ✅ | `BillingCycle`, `EnforceSubscription` (402) |
| All popular SA gateways, keys and sandbox/live in Settings | ✅ | 7 adapters, ADR-011 |
| SA tax invoices, credit notes, PDF + email | ✅ | `InvoiceService`, `BillingDocuments` |
| Active-learner metering | ✅ | invoice lines |
| Dunning: retries 1/3/7 days, read-only, final notice, suspension | ✅ | `BillingRules` (editable in Settings) |
| Messaging: WhatsApp (Meta), 6 SA SMS providers, failover, receipts, cost | ✅ | `modules/Messaging`, ADR-012 |
| Files: local / S3 (Cape Town) / Azure, direct upload, ClamAV, image variants | ✅ | `modules/Files`, ADR-010 |
| Centres: address, GPS, scope, accreditation, status; centre names in people list | ✅ | `CentreController`; centre managers see only their own centres (*close*) |
| "Verified provider" badge | ✅ *close* | `/v1/branding` → `verified`; shown on the branded sign-in page |
| `POST /v1/me/push-tokens` | ✅ *close* | `PushTokenController`, `PushService` (Expo push) |

### Web
| Item | Status |
|---|---|
| Public pricing and 4-step application (save and resume, upload progress, phone-friendly) | ✅ |
| Super Admin: applications, packages, subscriptions and invoices, gateways centre, message usage | ✅ |
| Provider: billing, centres with expiry badges and map pin, logo upload, read-only / past-due banners | ✅ |

### Mobile
| Item | Status |
|---|---|
| WhatsApp one-tap OTP autofill on Android; SMS Retriever fallback; iOS code autofill | ✅ *close* — `modules/code-autofill` (Kotlin), compiled by the new `android-native` CI job; JS wrapper unit-tested. *External:* proving it on a phone needs the EAS build and the approved one-tap template |
| Push foundation: token on the device record, test push from Settings | ✅ *close* |
| Read-only / suspended screens with clear guidance | ✅ *close* — and offline work is **kept** (402 is retried, not dropped), so no logbook entry is lost |

### 3D / simulators
| Item | Status |
|---|---|
| Simulator 1 specification workshop with TYM subject experts | ⏳ *external* — needs TYM's experts; the build starts in Sprint 3 once the signed-off specification exists |

## 3. Performance (spec §5)

Measured on the 2-core sandbox through the real stack (PHP dev server, which boots Laravel
on every request — Octane removes most of this), 60 sequential requests after 5 warm-ups:

| Endpoint | p50 | p95 | Budget |
|---|---|---|---|
| `GET /me` (Sprint 1 baseline) | 47 ms | 57 ms | 150 ms |
| `GET /centres` | 52 ms | 63 ms | 150 ms |
| `GET /billing` | 59 ms | 73 ms | 150 ms |
| `GET /invoices` | 48 ms | 61 ms | 150 ms |
| `GET /admin/applications` | 46 ms | 53 ms | 150 ms |
| `GET /admin/messages` | 46 ms | 56 ms | 150 ms |
| `GET /applications/code-available` | 43 ms | 54 ms | 150 ms |
| Payment webhook (rejected signature) | 41 ms | 55 ms | 200 ms |
| `GET /branding`, `GET /packages` | 2 ms | 3 ms | — (browser cache, by design) |
| Invoice PDF render | 80 ms | 92 ms (max) | 2 s |

- **Web:** initial JS is 119.5 KB gzip against a 160 KB budget, enforced in CI.
- **Mobile cold start:** *external* (needs a phone build).
- **Staging k6:** the run at target scale is still planned for the load-test sprint.

## 4. Security, PCI and POPIA (spec §5)

- **No card data on our servers (SAQ-A).** Hosted checkouts are used throughout.
- **Webhooks:** signatures are verified, then processed idempotently.
- **Encryption:** gateway and messaging credentials are encrypted and write-only in the UI.
- **Invoices** cannot be changed; corrections are credit notes. Every billing action is audited.
- **Uploads** are virus-scanned before use and served with `nosniff` and a locked-down CSP. Application documents can be opened only by platform staff.
- **Applications:**
  - The resume token is stored only as a hash and is never put in emails.
  - Lost tokens are recovered by reference plus an email code, which rotates the token.
  - Abuse protection: 10 applications or resume codes per IP per hour, and a honeypot field.
- **POPIA:** versioned consent on the application.
- **Push tokens** are tied to a device and cleared when Expo reports the app gone.
- **Found and fixed in 2d:** queued emails re-loaded rows that row-level security hides from workers. Mail now carries a snapshot, and a regression test serialises mail the way the queue does.

## 5. Accessibility

- **axe audit (WCAG 2.2 AA):** 27 screens, **0 violations**, run in CI. New this sprint:
  - billing, plan and pay dialogs
  - settings tabs and dialogs
  - messages
  - centres and the centre dialog
  - pricing, apply and resume
  - the applications queue and detail
- The branded sign-in page now shows the "Verified QCTO-accredited provider" badge, and it passes the audit.

## 6. Quality

| | Sprint 1 | Sprint 2 |
|---|---|---|
| API tests (Pest) | 179 | **246** (1,336 assertions) |
| JS tests (Vitest) | 31 | web 7 + mobile 15 + packages |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| CI jobs | 6 | 8 (`android-native` added; runs only when native code changes) |

## 7. Changes from the spec (for your approval)

1. **WhatsApp notices need a Meta "utility" template** whose body is `{{1}}`, set up in Settings.
   - Without it, decisions and reminders go by SMS and email.
   - Meta forbids business-started free text, so there is no other way.
2. **Signatures on workplace agreements** move to Sprint 3 with the agreements themselves (§9 of the Sprint 3 spec).
3. **Add-ons move to the sprints that build them.** AI credits and simulator access have
   nothing to sell yet. Adding them to packages is a small addition to billing (an add-on
   catalogue, quantities on the subscription, and lines on the invoice). It goes in with the
   first simulator release, so prices are set against a real product.
4. **Push uses Expo's push service** (FCM and APNs behind it) rather than direct Firebase keys.
   - That means one integration for both platforms.
   - `EXPO_ACCESS_TOKEN` is needed only if enhanced security is on.

## 8. Carried into Sprint 3

- **Per-provider custom roles** (carried from Sprint 1 and not done in Sprint 2). They fit with Sprint 3's staff work, so they are in that spec.
- **Accreditation scope** links to the qualification catalogue once it exists. Today scope holds SAQA IDs.

## 9. Needed from you / TYM

- Approve this report and the Sprint 3 spec.
- **Accounts:**
  - **Meta:** Meta Business verification, the authentication template (one-tap: package `za.co.skillsecosystem.app` plus the app's hash) and a utility template.
  - **Payments:** sandbox keys for the gateways you want to demo.
  - **Phone builds:** an Expo account, an Apple Developer account and a Google Play Console.
- **Simulator 1:** TYM subject experts for the specification workshop.
- The JV accountant to confirm the invoice wording and numbering (SARS).
