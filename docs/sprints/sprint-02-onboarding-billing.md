# Sprint 2 — Provider onboarding, billing and centres

**Plan dates:** 7–18 Dec 2026 (Phase 1 – SaaS compliance core). **Status:** approved 22 Nov 2026 (decisions in §9); in build.
**Goal (from the sprint plan):** *TPs can sign up, pay and set up centres.*
Sprint 1 already delivered custom domains with auto SSL, branding, and TP code/QR in
the app. This sprint finishes the rest of that goal and the Sprint 1 carry-overs: real
WhatsApp/SMS, uploads, and full centres.

**Demo at the end:**
1. A new training provider applies on the public sign-up page and uploads its QCTO
   accreditation letter.
2. The Super Admin verifies it from a checklist.
3. The provider picks an (example) package and pays by card or Instant EFT.
4. It receives a VAT tax invoice.
5. It adds two centres, each with its accreditation scope and expiry.
6. It goes live on its own domain.
7. A learner signs in on the phone with a **real WhatsApp PIN**, filled in by one tap
   on Android.

---

## 1. What leaders do, where they fall short, and what we add

| Area | Leaders | What they do well (we adopt) | Their weakness here | Our extra |
|---|---|---|---|---|
| Self-serve sign-up | Shopify, Thinkific, Teachable | Start in minutes; guided set-up checklist; trial without a card | Anyone can sign up — no check that a "college" is real | **Accreditation-verified onboarding:** QCTO SDP number, CIPC number, accreditation letter and expiry; Super Admin review queue with a checklist; a "Verified provider" badge learners and employers can trust |
| Subscriptions & invoices | Stripe Billing, Chargebee, Paddle | Plans, trials, upgrades with proration, dunning, self-service billing portal | Stripe is not available in SA. Card-only, priced in USD; invoices are not SARS tax invoices | **ZAR billing with SA tax invoices** (15 % VAT, supplier and recipient VAT numbers); card, Instant EFT and Capitec Pay; **pay by EFT on invoice** for larger TPs; price per *active learner*, so small TPs pay little |
| Collections | Stripe Smart Retries, Chargebee dunning | Automatic retries and reminders | Email-only reminders, which SA customers often miss | **Dunning by WhatsApp, SMS and email** with a pay link; grace period and read-only mode **before** suspension, so learners never lose a logbook |
| Messaging gateways | Twilio, Clickatell, Meta Cloud API | Delivery receipts, templates, failover | Per-product set-up; no per-tenant cost view | **Super Admin gateway centre:** encrypted credentials, test send, channel order (WhatsApp → SMS → email), delivery status and **cost per provider** for re-billing |
| Phone sign-in code | WhatsApp OTP (Meta), Google SMS Retriever | Codes arrive in WhatsApp; one-tap autofill | Usually SMS-only in SA, which is costly | **WhatsApp authentication template with one-tap autofill on Android**; SMS only as fallback. A WhatsApp code costs about a quarter of an SMS |
| Sites / branches | LMS "branches", HR systems | Multi-site structure | Sites carry no accreditation, so nothing warns when it lapses | **Centres as accredited delivery sites:** scope (curricula), accreditation number and expiry; **90/60/30-day expiry alerts**; GPS location (used for attendance in Sprint 5) |
| Files | Google Drive, Dropbox | Easy upload and preview | Data stored outside SA; no scanning | **POPIA-friendly storage in Cape Town (af-south-1):** direct-to-storage uploads, virus scan before use, image resizing for logos |

## 2. Scope

### 2.1 Backend (`apps/api`)
**New modules:** `Onboarding`, `Billing`, `Messaging`, `Files`; **Centres** extended in
`Tenancy`.

**Onboarding**
- Public application (on the platform site):
  - Organisation details and CIPC number.
  - QCTO SDP accreditation number and expiry.
  - Contact person (becomes the owner).
  - Accreditation letter upload; POPIA consent.
- Review queue for the Super Admin:
  - Checklist: CIPC verified, accreditation letter matches, expiry valid, contact
    verified.
  - Approve, ask for more information, or decline, with the reason sent by email
    and WhatsApp.
- On approval: tenant created (Sprint 1 service), subdomain live, owner invited.
- Accreditation expiry watcher: alerts at 90/60/30 days, at provider and centre level.

**Billing**
- Packages are managed by the Super Admin (**all prices shown are examples and can
  be changed**):
  - Base monthly fee + per-active-learner fee + add-ons (AI credits, simulator access).
  - Trial days.
  - Annual discount.
- Subscriptions: trial → active → past due → read-only → suspended. Upgrades and
  downgrades are prorated; cancellation takes effect at period end.
- Payment-gateway adapter interface with **all popular SA gateways** (decision 1); the Super Admin
  enters keys, picks sandbox/live and enables each one in Settings.
  - Hosted/inline checkout, so card data never touches our servers (PCI SAQ-A).
  - Tokenised recurring charges.
  - Signed webhooks, processed idempotently.
- SA tax invoices: sequential numbers, 15 % VAT, supplier and customer VAT
  details, PDF and email; credit notes.
- Active-learner metering (monthly snapshot from memberships), shown on the invoice.
- Dunning: retries on days 1, 3 and 7; messages through `Messaging`; read-only after
  the grace period; suspension only after a final notice.

**Messaging**
- Gateway configs stored encrypted:
  - WhatsApp (Meta Cloud API, authentication + utility templates).
  - SMS (decision 2).
  - Email (SMTP/SES).
- Channel order per message type; delivery webhooks; message log with status and
  cost; per-tenant usage.
- The OTP channel switches from the log driver to real providers (the Sprint 1
  interface is unchanged).

**Files**
- Storage chosen by the Super Admin in Settings: **local disk, AWS S3 (Cape Town by default,
  any S3-compatible) or Azure Blob**. Uploads go direct to the cloud store where it
  supports signed URLs. Each file records the store it was written to, so switching store
  later never breaks old files.
- Virus scan (ClamAV) before a file is usable; image variants for logos.
- Tenant-scoped, encrypted at rest; signed short-lived download links.

**Centres (full module)**
- Name; physical address with GPS; accreditation number, scope (curriculum codes,
  linked to the qualification catalogue in Sprint 3) and expiry; status.
- Centre managers; the people list shows centre names (Sprint 1 carry-over).

### 2.2 Web (`apps/web`)
- **Public sign-up:** a pricing page with example packages and a 4-step application
  wizard (save and resume; upload with progress; mobile-friendly).
- **Super Admin:**
  - Applications queue with document viewer and checklist.
  - Packages editor.
  - Subscriptions and invoices.
  - Gateways centre (credentials, test send, channel order, delivery log, cost).
  - Messaging usage per provider.
- **Provider owner:**
  - Billing: plan, usage, invoices, payment method, upgrade.
  - Centres: list, map pin, accreditation expiry badges.
  - Logo upload in Brand & domain.
  - Read-only/past-due banners with a pay button.

### 2.3 Mobile (`apps/mobile`)
- WhatsApp one-tap OTP autofill on Android, with SMS Retriever as fallback; iOS code
  autofill.
- Push-notification foundation: device push tokens via the Sprint 1 device record, and
  a test push from Settings.
- Read-only/suspended-provider screens with clear guidance.

### 2.4 3D / simulators (Mobile & 3D squad)
- Simulator 1 **specification workshop with TYM subject-matter experts**:
  - Trade and tasks.
  - Scoring rubric mapped to the practical module.
  - Safety errors to detect.
  - Device targets (web, low-cost Android, VR).
- Output: a signed-off specification and greybox scene list. The build starts in
  Sprint 3.

## 3. Data model (new / changed)

| Table | Purpose / key columns |
|---|---|
| `tenant_applications` | org details, CIPC no., SDP no., accreditation expiry, contact, status (draft/submitted/info_requested/approved/declined), reviewer, checklist (json), decided_at |
| `files` | tenant_id, owner, purpose, path, mime, size, sha256, scan_status, variants (json) |
| `packages`, `package_prices` | code, name, description, features (json), trial_days, base fee, per-learner fee, interval, currency ZAR, active, `is_example` flag |
| `subscriptions` | tenant_id, package, status, period start/end, trial_ends_at, cancel_at, gateway customer/subscription refs |
| `invoices`, `invoice_lines`, `credit_notes` | number (sequential per series), tenant, VAT details, totals excl./VAT/incl., status, pdf file |
| `payments`, `payment_methods` | gateway, reference, amount, status, raw webhook (encrypted); tokenised method (brand, last4, expiry) |
| `integrations` | type (payment/sms/whatsapp/email/storage), driver, credentials (encrypted), settings, mode (sandbox/live), enabled, priority, last test result |
| `platform_settings` | key (e.g. `company`), value (json): JV registered name, VAT number, address, invoice prefix |
| `message_log` | tenant, channel, template, destination (masked), status, cost, provider ref |
| `centres` (extend) | address, lat/lng, scope (json), accreditation fields, alert state |

Every tenant-owned table gets `tenant_id` + forced RLS. The CI catalogue test enforces
this.

## 4. API (v1, additions)
- `POST /v1/applications` (public, rate-limited) · `POST /v1/applications/{id}/files`
- `GET/POST /v1/admin/applications` · `POST …/{id}/approve|request-info|decline`
- `GET/POST/PATCH /v1/admin/packages`
- `GET /v1/admin/subscriptions` · `GET /v1/admin/invoices`
- `GET/PUT /v1/admin/gateways` · `POST …/{id}/test` · `GET /v1/admin/messages`
- `GET /v1/packages` (public) · `GET /v1/billing` · `POST /v1/billing/checkout` ·
  `POST /v1/billing/change-plan` · `POST /v1/billing/cancel` · `GET /v1/invoices` ·
  `GET /v1/invoices/{id}/pdf`
- `POST /v1/webhooks/payments/{gateway}` · `POST /v1/webhooks/messaging/{gateway}`
  (signed, idempotent)
- `POST /v1/files` (presign) · `POST /v1/files/{id}/complete`
- `GET/POST/PATCH /v1/centres`
- `POST /v1/me/push-tokens`

## 5. Performance, security and compliance
- **Budgets:**
  - Unchanged from Sprint 1.
  - Webhook handling < 200 ms: acknowledge, then process in a queue.
  - Invoice PDF < 2 s, generated in the queue.
- **PCI:** no card data on our servers (SAQ-A); webhooks verified by signature, then
  processed idempotently.
- **Data and secrets:**
  - Gateway credentials and webhook payloads are encrypted.
  - Data stays in SA (af-south-1). Uploads are virus-scanned before use.
- **Audit and access:**
  - Every billing action is audited.
  - Invoices are immutable; corrections are credit notes.
- **POPIA:** consent on the application form; the operator agreement is accepted at
  sign-up.

## 6. Out of scope
- Qualification catalogue and cohorts (Sprint 3).
- Enrolment (Sprint 4).
- Debit orders (later, if TPs ask).
- Multi-currency (Sprint 30).

## 7. Definition of done
- Demo script runs end to end in staging with the gateway **sandbox** and a real
  WhatsApp test number.
- Every item above built with tests; CI green; merged.
- Isolation tests cover the new tables; budgets met; axe audit clean on the new screens.
- Close report with evidence; the sprint sheet is updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Meta Business verification / template approval takes days | Start now; SMS fallback keeps sign-in working |
| Gateway merchant approval (KYC) for the JV | Build against the sandbox; production keys are swapped in later |
| VAT rules for tax invoices | Use SARS tax-invoice requirements; the JV's accountant confirms numbering and wording |
| Scope is full for two weeks | Billing first (week 1); gateways centre and centres in week 2; simulator workshop runs in parallel |

## 9. Decisions (approved 22 Nov 2026)
1. **Payment gateways:** all popular SA gateways, each set up by the Super Admin in
   Settings (keys, sandbox/live, enable/disable):
   - Paystack, PayFast, Peach Payments, Ozow, Yoco, Stitch and iKhokha.
   - Each gateway declares what it supports (recurring card, one-off card, instant
     EFT). Subscriptions use the ones that support recurring; invoices can be paid
     through any enabled gateway.
2. **SMS:** all major SA providers, each enabled or disabled by the Super Admin:
   - Clickatell, BulkSMS, SMSPortal, Panacea Mobile, Infobip and Twilio.
   - The admin sets the order; the next one is used if one fails.
3. **WhatsApp:** Meta Cloud API directly, set up in Settings. *Action for the JV:*
   start Meta Business verification and get a dedicated number.
4. **Storage:** the Super Admin chooses local disk, AWS S3 (af-south-1 by default) or
   Azure Blob in Settings, with a connection test.
5. **Pricing shape (examples only):**
   - Base fee + per active learner + add-ons; 14-day trial.
   - Read-only after 7 days overdue; suspended after 21.
6. **Company details** (JV registered name, registration and VAT numbers, address, invoice
   prefix) are set in **Settings → Company** and printed on every tax invoice.
7. **Simulator 1:** TYM picks the trade and names 1–2 subject experts.

## 10. Build order
| Part | Content |
|---|---|
| 2a | Settings framework: Company; integrations catalogue with encrypted keys, enable/disable, order and tests; storage (local / S3 / Azure); Files module (direct upload, virus scan, image variants); logo upload |
| 2b | Billing: packages, subscriptions, payment gateway adapters, webhooks, tax invoices, dunning |
| 2c | Messaging: WhatsApp Cloud API and the SMS adapters, delivery receipts, message log and costs; real OTP; Android one-tap autofill |
| 2d | Onboarding: public sign-up and pricing, application review, centres with accreditation scope and expiry alerts |
| close | Load and accessibility checks, report, Sprint 3 spec |

---

## 11. Part 2b: Billing. What leaders do, their weak spots, and what we add

Leaders reviewed: Stripe Billing, Chargebee, Paddle and Recurly (global subscription
billing); Paystack and PayFast subscriptions (South Africa); Thinkific/Teachable
(LMS self-billing).

| Area | What leaders do (we adopt) | Weak spots for SA training providers | Our addition |
|---|---|---|---|
| Plans | Flat + per-seat + usage tiers; trials; annual discount; proration on upgrade (Stripe, Chargebee) | Usage must be converted to units by hand; seats ≠ active learners | Price per **active learner** counted from real enrolments each month. A **live "next invoice" estimate** on the billing page, so there are no surprises |
| Payment methods | Card on file and wallets; Chargebee supports 20+ gateways | Stripe isn't available to SA merchants; card-only flows leave out EFT, which TPs use most | Card (tokenised) **and** Instant EFT **and** pay-by-EFT on invoice with a unique reference. **Any enabled gateway** can pay any invoice; if one gateway is down, the TP picks another |
| Invoices | Auto-numbered, emailed PDFs | Not SARS tax invoices: no supplier/recipient VAT numbers, VAT not shown in rands, USD | **SARS-compliant full tax invoices** every time: "Tax Invoice", supplier and recipient name, address and VAT number, sequential number, date, description, VAT in rands. They can't be edited after issue; corrections are **credit notes** |
| Failed payments | Smart retries and dunning emails (Stripe recovers ~38%; Chargebee dunning) | Email-only reminders; hard cut-off ends access abruptly | Retries on days 1, 3 and 7, plus reminders (email now, WhatsApp/SMS in 2c). **Grace → read-only → suspended** (7 and 21 days, set by the Super Admin). Read-only keeps every learner record, logbook and PoE visible and safe; nothing is deleted |
| Self-service | Customer portal: change plan, update card, download invoices (Stripe, Chargebee) | Often a separate hosted site that doesn't match the brand | Billing lives **inside the provider's own branded console**: plan, usage, invoices, payment method, change plan, cancel at period end |
| Control for the owner | Dashboards for MRR and churn | Little view of collections per gateway | Super Admin billing view: subscriptions by status, overdue amounts, invoices, payments per gateway, and a manual **"mark EFT received"** with audit |
| Tax and audit | Paddle acts as merchant of record | Merchant of record isn't needed in SA, and the fees are high | JV invoices directly; immutable invoices; every billing action audited; payment events idempotent (each gateway event processed once) |

**2b builds:**
- Packages, subscriptions, active-learner metering and the next-invoice estimate.
- Sequential tax invoices with PDFs; credit notes.
- The gateway adapter interface, with **Paystack** (cards, tokenised renewals,
  HMAC-SHA512 webhooks) and **PayFast** (hosted checkout with tokenisation, ITN
  validation, ad hoc charges).
- Pay-by-EFT invoices; the renewal, retry and dunning state machine.
- Read-only and suspended enforcement.
- The provider billing page and the Super Admin billing screens.
- Example packages seeded and marked **"Example pricing — can be changed"**.

**2b-ii** adds the Peach Payments, Ozow, Yoco, Stitch and iKhokha adapters on the same
interface, each built against its current API documentation.
