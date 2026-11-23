# Sprint 4: Applications and enrolment (close report)

**Status:** Build complete. Parts 4a to 4e merged with CI green. Items that wait on accounts, documents or people outside the code are marked *external*.

**Pull requests:**
- #19 intakes and applications
- #20 learner documents
- #21 offers, consent and enrolment
- #22 QCTO enrolment list, ID checks and retention
- #23 mobile, application PDF and close (this report)

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. Provider publishes an intake for an open cohort and shares it by WhatsApp | ✅ | Demo: "Bricklayer, December 2026", link `/join/bricklay` with a QR code. Bricklayer is published **in the demo database only** (see §9) |
| 2. Learner applies on a phone, SA ID checked as typed, photographs ID and highest qualification; photo quality and certification date checked | ✅ | Web form: PIN to the phone signs the applicant in. App: apply (offline drafts through the outbox), camera capture with barcode reading of the smart ID card (PDF417) or ID book (Code 39). The server checks sharpness, glare, size and certification age |
| 3. Provider sees every document checked, shortlists and offers | ✅ | Applicant page: checks per document, accept or reject with reasons, one-tap "ask for what is missing". Offers are refused until every required document is accepted and a place is free |
| 4. Learner accepts in the app and signs with a PIN; under-18s need a guardian | ✅ | `/offer/{token}` on the web, or Offers in the app, then "Sign my agreement" opens in-app signing. The guardian is added as a signer and gets their own link |
| 5. POPIA consent, versioned and recorded | ✅ | Provider-editable wording with versions; the consent records the version, who gave it and the channel |
| 6. Learner enrolled; readiness counts them; the app shows only the enrolled course | ✅ | Signing completes the enrolment (`AgreementCompleted`). Readiness has a Learners group. "My course" lists only enrolments; `mayLearn()` is the rule for Sprint 5 |
| 7. Provider sees the learner on the QCTO enrolment list with days left | ✅ | Per-cohort list to the QCTO data load spec, missing fields by column, countdown and reminders, `.xlsx` download, "Mark as sent". Demo: Bongani Khumalo is enrolled with three details still to fill in |

## 2. Scope checklist (spec §2)

### Backend
| Item | Status | Where |
|---|---|---|
| Intakes, applications, pipeline, waiting list | ✅ | `modules/Enrolment`, ADR-019 |
| A PDF of each application kept on the record | ✅ (added at the close: it had been missed in 4a) | `ApplicationPdf` |
| Documents: types, certification rules per provider, quality and ID checks, re-request | ✅ | ADR-020 |
| Offers with expiry, lapse, waiting list moving up; funding source | ✅ | `OfferService`, ADR-021 |
| Versioned POPIA consent; guardian consent and co-signature for under-18s | ✅ | ADR-021 |
| Enrolments on signing; learner role; access rule | ✅ | `EnrolmentService` |
| Readiness per learner | ✅ capacity and unsigned acceptances | `Readiness::learners` |
| QCTO enrolment list, due date, reminders | ✅ and **the file itself**, brought forward from Sprint 8 | `QctoEnrolmentList`, ADR-022 |
| Identity check connector, off-peak batch | ✅ interface, queue, batch and comparison; ⏳ *external* the reseller adapter | ADR-022 |
| Deleting applicants who never enrol (setting) | ✅ | `enrolment:purge-applicants` |

### Web
| Item | Status |
|---|---|
| Intakes, board, applicant page with documents, offers, ID check and application PDF | ✅ |
| Rules: documents, QCTO days, working days, retention, automatic ID checks | ✅ |
| Consent wording with versions | ✅ |
| Cohort → QCTO enrolment page, with staff fixing missing details in place | ✅ |
| Public: `/join/{slug}`, `/offer/{token}` | ✅ |
| Profile: the fields the QCTO file needs | ✅ |

### Mobile
| Item | Status |
|---|---|
| Apply, with offline drafts sent through the outbox | ✅ |
| Document scanner with ID barcode reading | ✅ |
| Offers: accept with consent (and guardian), then sign in the app | ✅ |
| My course: enrolled courses only | ✅ |
| Home cards: offers waiting, documents needed, apply | ✅ |
| Push notice for an offer opens it (strict id pattern) | ✅ |

### 3D / simulators
| Item | Status |
|---|---|
| Simulator 1 interactions | ⏳ *external*: waiting for the simulator 1 specification workshop with TYM (proposed 3 Dec) |

## 3. Performance (spec §5)

Measured in-process on the 2-core sandbox:

| Operation | p50 | p95 | Budget |
|---|---|---|---|
| Applications board, 2,000 applications in one intake | 108 ms | 124 ms | 150 ms |
| QCTO enrolment list for a cohort | 9 ms | 14 ms | — |
| Cohort readiness (with learner checks) | 9 ms | 14 ms | 300 ms |

- **Web:** initial JavaScript is 121.1 KB gzip of the 160 KB budget. Every new page loads on demand.
- **Mobile:** the Android bundle builds (Hermes) in CI.

## 4. Security and POPIA

- **Row-level security** on every new tenant table: intakes, applications, learner documents, offers, consent texts, enrolments, QCTO submissions, ID checks.
- **Offer links:** 48 random characters, stored as a hash, rate-limited.
  - Accepting by link records consent only. Who the person is gets confirmed by the PIN when they sign the agreement.
- **Consent:** the exact wording version is recorded. An accept against an older version is refused, so nobody agrees to wording they did not see.
- **Under-18s:** a guardian's own phone number is required (not the learner's), and the guardian signs too.
- **ID checks:** off unless connected. Results are stored as a summary (names, date of birth, alive), never the raw record.
- **Retention:** applicants who never enrol are removed after 12 months by default. Consent records stay as evidence. Staff, and anyone ever enrolled, are never touched.
- **Gender for the QCTO file** is derived from the SA ID number when the file is made, as the QCTO requires. It is not stored as equity data.

## 5. Accessibility

axe (WCAG 2.2 AA): **69 screens, 0 violations**, in CI. New in Sprint 4 (17 screens):
- intakes, the board, the decline and rules dialogs
- the applicant page (documents, review dialog, offer card)
- consent wording
- the QCTO enrolment page with its fix and sent dialogs
- the public join and offer pages

## 6. Quality

| | Sprint 3 | Sprint 4 |
|---|---|---|
| API tests (Pest) | 295 | **323** (2,472 assertions) |
| Mobile tests | 17 | 22 |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| ADRs | 18 | 22 (ADR-019 to 022) |
| Route collision guard | — | a test fails the build if two routes share a method and path shape (after a 4b clash) |

## 7. Changes from the spec (for your approval)

1. **The QCTO file is built now, not in Sprint 8.** The data load spec is public, and checking fields without producing the file would leave the provider retyping it. Sprint 8 keeps the second submission (readiness for the EISA, columns AK to AP).
2. **The QCTO deadline defaults to 21 calendar days.** The QCTO's FAQ says 21 days. Its enrolment page and 2023 notice say 21 working days. Calendar days is the earlier deadline, so the safer default. Providers can switch to working days, which count SA public holidays.
3. **Enrolment happens when the agreement is fully signed**, not when the offer is accepted. Billing (the learner role) starts then too.
4. **Consent reuses Sprint 1's `consents` table**, which gains the wording version, who gave it and the channel, rather than a second table.
5. **API paths:**
   - Provider routes for learner applications are `/v1/learner-applications…`, because `/v1/applications/{id}` belongs to provider onboarding.
   - The QCTO list is `/v1/cohorts/{id}/qcto-enrolment`.
6. **Mobile documents need a connection.** Applying works offline. Photos are uploaded straight away, so the quality check can ask for a retake while the person still has the document in hand.

## 8. Carried into Sprint 5

- **Mobile profile:** the QCTO fields (title, Stats SA area code and so on) are on the web profile and the staff fix dialog, not yet in the app.
- **Stats SA area codes** are typed in by hand. A lookup from the address comes later.
- **Visual check of the mobile screens on a phone** needs the EAS build (*external*). The screens are type-checked, bundled, and their logic is unit-tested.

## 9. Needed from you / TYM

- Approve this report and the Sprint 5 spec (`sprint-05-classes-attendance.md`).
- **TYM:**
  - The Bricklayer (93627) curriculum document, and whether its enrolment is extended past 30 Dec 2026. Until then it is published in the demo only.
  - Whether the QCTO's "Qualification Id" (column B) is the SAQA ID or the QCTO's own code.
  - Confirm calendar or working days for the 21-day rule, if they have a QCTO answer.
  - The simulator 1 workshop (3 Dec).
- **JV:** pick the Home Affairs reseller (for example one with a sandbox). The adapter is about a day's work once there are API docs.
- **Accounts (still open):** LAWtrust sandbox; Meta templates; gateway sandbox keys; Expo, Apple and Google accounts.
