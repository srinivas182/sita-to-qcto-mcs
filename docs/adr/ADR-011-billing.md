# ADR-011: Billing engine on our side, gateways as interchangeable adapters

**Status:** Accepted (Sprint 2b, 22 Nov 2026)

## Context
- Training providers pay monthly or yearly: a base fee plus active learners above an
  included number.
- They pay by card, Instant EFT or bank EFT, through South African gateways. Stripe
  Billing and Chargebee are not options here: Stripe is not available to SA merchants,
  and Chargebee's invoices are not SARS tax invoices.
- Invoices must be SARS tax invoices.
- Overdue accounts need graded limits that never put learner records at risk.

## Decision
- **We own subscriptions, invoices and collections** (Billing module). Gateways only
  move money: hosted checkout, saved-card (token) charges and signed webhooks, behind
  one `PaymentGateway` interface. Paystack and PayFast come first; Peach Payments,
  Ozow, Yoco, Stitch and iKhokha follow on the same interface.
- **Money** is integer cents in ZAR. Prices exclude VAT; VAT is added per invoice at
  the rate in Settings → Company and rounded half up.
- **Tax invoices:**
  - Each has a gap-free sequential number (`document_sequences`, row lock inside the
    issuing transaction).
  - Supplier and recipient particulars are captured as snapshots at issue.
  - Invoices are immutable; corrections are numbered credit notes (`CN-…`).
  - PDFs are rendered once with Dompdf (no remote resources) and kept in the provider's
    file store.
- **Metering** counts active learners (active membership + learner role) when the
  invoice is built. A live estimate is shown on the billing page.
- **Collections:**
  - `billing:run` runs every 15 minutes, one provider at a time in its own RLS context.
  - It handles trial reminders, the first invoice when a trial ends, and renewals
    (pending downgrades and cancellations apply at period end).
  - Saved cards are charged automatically, with retries on the configured days.
  - Status follows from the oldest overdue invoice: past due → read-only → suspended.
    The days are set in Settings → Billing rules.
- **Enforcement** is middleware:
  - Read-only refuses writes.
  - Suspended allows only sign-in and billing.
  - Ended plans are read-only.
  - Nothing is ever deleted, and providers can always reach billing to pay.
- **Webhooks:**
  - Verified with each gateway's own scheme: Paystack uses HMAC-SHA512; PayFast uses
    an MD5 signature plus server validation.
  - Stored once per gateway event (`gateway_events`, unique per driver and event, with
    the payload encrypted).
  - Amounts are checked against our payment before an invoice is marked paid.
- **Upgrades** invoice the prorated base-fee difference now. Downgrades wait for period
  end. Changing interval credits the unused part and starts a new period.

## Consequences
- Moving gateway or adding one never touches billing logic or invoices.
- A scheduler must run in every environment (`schedule:work` locally;
  `schedule:run` from cron, or a scheduler container, in production) alongside a
  queue worker.
- Gateway behaviour is tested against recorded API shapes (HTTP fakes). Each adapter
  is confirmed once in its sandbox when the JV's merchant accounts exist.
