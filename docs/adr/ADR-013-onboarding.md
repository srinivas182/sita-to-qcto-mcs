# ADR-013: Provider onboarding — applications, review, centres

- Status: Accepted
- Date: 2026-11-22
- Sprint: 2d

## Context

Training providers must be QCTO-accredited before they can use the platform. Leading
platforms either accept anyone (and verify late) or run onboarding by email and
spreadsheets. We want self-service applying with the checks a reviewer would make,
then approval in one step that creates everything the provider needs.

## Decision

1. **Public application on the platform's own site** (`/apply`, refused on provider hosts).
   No account is created until approval. The applicant holds a random 48-character
   resume token (stored hashed); a lost token is replaced by reference + email code,
   which rotates it. Emails never contain the token.
2. **Automatic checks while typing**: email confirmed, CIPC number format
   (`YYYY/NNNNNN/NN`), QCTO number format (`NN-QCTO/SDP|AC|AC-TTC` + 12 digits),
   accreditation in date and within 5 years, VAT format, address, web address free,
   no duplicate company/accreditation, letter uploaded and virus-checked, POPIA consent
   (versioned). Submission requires all required checks. Website/email domain match is
   a signal for the reviewer, not a blocker.
3. **Human review with a recorded checklist** (CIPC found, letter matches, scope,
   contact authorised). Approve is blocked until every item is ticked. Live CIPC/QCTO
   lookups are not automated yet: neither offers a public API. A `verification`
   integration type can be added later without changing the flow.
4. **One-step approval** in a single transaction: register + approve tenant, create the
   first centre from the application, start the trial (or save the plan choice), invite
   the applicant as owner. Request-info sends the application back for edits; decline
   records a reason. Every decision is emailed.
5. **Centres carry accreditation**: type, number, expiry, scope (SAQA IDs), address,
   location. A daily job emails owners and that centre's managers at 90, 60 and 30 days
   and on expiry — once per threshold; changing the expiry resets it.
6. **Documents** reuse the Files module (`application_document`, platform scope,
   10 MB, PDF/JPG/PNG, ClamAV) and are streamed only to platform staff.
7. **Queued emails carry a snapshot**, never models: workers run without the platform
   context, so row-level security would hide the row.

## Consequences

- Abuse limits: 10 new applications/resume codes per IP per hour, honeypot field, codes
  expire in 30 minutes with an attempt limit.
- Applications stay in the platform database (platform-only RLS); approved ones link
  to the tenant for audit.
