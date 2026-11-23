# Sprint 4 — Applications and enrolment

**Plan dates:** 4 – 15 Jan 2027 (Phase 1 – SaaS compliance core).
**Status:** approved 23 Nov 2026 (recommendations in §9 accepted). Built: see `sprint-04-report.md`.

**Goal (from the sprint plan):** *Learners enrolled correctly first time.*

**Demo at the end:** *A learner is enrolled end to end on a phone.*
1. The provider publishes an intake for an open cohort and shares the link by WhatsApp.
2. A learner applies on their phone. They enter their SA ID (checked as they type) and photograph their ID and highest qualification. The app checks the photo is sharp and complete, and records the certification date.
3. The provider sees the application with every document checked: certified within the allowed time, ID matching the photo page, nothing missing. They shortlist and offer a place.
4. The learner accepts in the app and signs the enrolment agreement with a PIN. A learner under 18 also needs a parent or guardian to sign.
5. They give POPIA consent, versioned and recorded.
6. The learner is enrolled. The cohort's readiness check now counts them, and the app shows only the enrolled course.
7. The provider sees the learner on the QCTO enrolment list, with the days left to submit it.

---

## 1. Where the market stands (Nov 2026)

**Regulation:**
- **QCTO enrolment data:**
  - Accredited providers send QCTO learner enrolment data for occupational qualifications. QCTO publishes a data-load specification and a file naming convention (`LEISAyyyymmdd-<SDP name>`).
  - One provider's published guide says the enrolment spreadsheet is due **within 21 days of the training start**. We will confirm this with the QCTO and TYM; the rule is configurable either way.
  - The full export is Sprint 8. Sprint 4 captures **every field it will need**, checked against the QCTO specification during the build.
- **Legacy dates:** no new enrolments on legacy qualifications after their last enrolment date (30 Jun 2026 for most pre-2009 qualifications, or the extended date for the 630 extended ones). The cohort check (Sprint 3) already enforces this.
- **Certified copies:**
  - The law sets no expiry date for a certified copy.
  - Most institutions accept copies certified within **3 months**. Government departments accept copies up to **6 months** old for job applications.
  - So the age limit is a provider setting, not a hard-coded rule.
- **Identity checks:**
  - Home Affairs' new verification service (live since July 2025) gives verified partners real-time checks at R10 and off-peak checks at R1.
  - Accredited resellers (for example Experian, TransUnion and Didit) offer it through APIs.

| Area | Leaders | What they do well (we adopt) | Their weakness here | Our extra |
|---|---|---|---|---|
| Applying | aXcelerate Learner Onboarding (form builder, payment step, document upload to a portfolio, "light-touch" first form); SA systems (skillSYMS and others) | Short first form, more details after acceptance; required documents marked; resume later | Built around Australian rules (USI, AVETMISS); online forms historically through a WordPress plugin; the enrolment form as a PDF on the learner's record is still on their roadmap; email-first | **Phone-first application** by link or QR, **WhatsApp** updates, works offline and sends when online. A **PDF of the application** is kept on the record automatically |
| Documents | aXcelerate portfolio checklists; manual checks | Required-document checklists | Staff check each scan by eye; no certification-age rule; blurry photos are found weeks later | **Camera capture with a quality check** (sharpness, glare, all four corners). The provider's **certification-age rule** (default from §9). **ID number cross-check** (checksum, date of birth, gender against the photographed ID). Missing or failing documents block an offer, with a one-tap request to the learner |
| Identity | Most SA systems: none beyond the checksum | — | Duplicate and false identities; the same person enrolled at two providers | Checksum and duplicate check across the **whole platform** (Sprint 1 hash). **Optional Home Affairs check** through a reseller connector, batched off-peak to keep the cost near R1 (§9) |
| Selection | aXcelerate enquiry → enrolment | Status pipeline | No link to capacity or eligibility | Pipeline **applied → shortlisted → offered → accepted → enrolled**, showing places left (cohort capacity and workplace places). Offers lapse; a waiting list moves up automatically |
| Agreements and consent | DocuSign-style signing; paper consent | Signed agreements | No guardian signature for minors; consent not versioned | Enrolment agreement signed **in the app with a PIN** (Sprint 3 engine). **Guardian co-signature for under-18s**. **POPIA consent versioned** with the exact text accepted |
| Access | LMSs open the whole catalogue to every learner | — | Learners see material they haven't enrolled for, which the QCTO doesn't allow | **Enrolment unlocks only the enrolled course**: an access rule at the API, not a menu setting |
| Reporting | Spreadsheets emailed to QCTO | — | Late or incomplete enrolment returns | **QCTO enrolment list** per cohort with missing fields flagged and a **due-date countdown** (export file in Sprint 8) |

## 2. Scope

### 2.1 Backend (`apps/api`)
- **New module `Enrolment`:**
  - **Intakes:** an open cohort published for applications, with closing date, places, requirements (documents, minimum age, prior qualification) and a share link and QR.
  - **Applications:** applicant details, the answers asked, documents, status pipeline with reasons, waiting list. A PDF of the application is kept on the record.
  - **Documents:** type (ID, qualification, proof of address, etc.), file, certification date and certifier, checks (quality, age, ID match) and their results. Re-request by message.
  - **Offers:** valid until a date. Acceptance leads to the enrolment agreement (`Agreements`, kind `learner`), with a guardian signer when the learner is under 18.
  - **Enrolments:** learner ↔ cohort, status (active, suspended, withdrawn, completed), funding source (e.g. CETA discretionary grant, employer, self), dates.
- **POPIA consent:** versioned texts per purpose, with acceptance records (text version, time, channel) and withdrawal.
- **Access rule:** learning endpoints (Sprint 5) check an active enrolment for that qualification. Sprint 4 adds the rule and its tests.
- **Readiness check, per learner:** ID document checked, agreement signed (and the guardian's, if needed), consent given; a workplace agreement before placement.
- **QCTO enrolment list:** the fields the QCTO specification needs, gaps flagged, and the due date counted from the cohort's start.
- **Identity check connector** (optional, §9): an interface, one reseller adapter and an off-peak batch queue.

### 2.2 Web (`apps/web`)
- **Provider:**
  - Intakes: publish, share, close.
  - Applications board, with document checks shown and actions to shortlist, offer and decline.
  - The learner record.
  - The QCTO enrolment list.
- **Public:** the intake page (server-rendered, shareable) and the application form for people without the app.

### 2.3 Mobile (`apps/mobile`)
- **Apply:** from a link or QR; offline drafts sent through the outbox.
- **Document scanner:** quality check on the phone, so files stay small for low data.
- **Offers:** accept, sign the agreement with a PIN, give consent.
- **After enrolling:** "My course" unlocks only the enrolled qualification.

### 2.4 3D / simulators
- Simulator 1 interactions (plan row), once the specification is signed off.

## 3. Data model (new)

| Table | Key fields |
|---|---|
| `intakes` | cohort, opens/closes, places, requirements, public slug |
| `applications` | intake, applicant (user), status, answers, reasons, waiting-list position |
| `learner_documents` | person, type, file, certified_on, certifier, checks (json), status |
| `offers` | application, expires_at, status, agreement |
| `enrolments` | person, cohort, status, funding source, enrolled_on, ended_on |
| `consent_texts`, `consents` | purpose, version, text; person, version, given_at, channel, withdrawn_at |
| `identity_checks` | person, provider, status, result summary, cost, checked_at |

Every table gets `tenant_id` and forced RLS, except consent texts, which are versioned per provider.

## 4. API (v1, additions)

- `GET/POST/PATCH /v1/intakes`
- `GET /v1/public/intakes/{slug}` (public)
- `POST /v1/applications` (applicant) · `GET/PATCH /v1/applications` (provider) · `POST /v1/applications/{id}/offer|decline|waitlist`
- `POST /v1/me/documents` · `POST /v1/documents/{id}/check`
- `GET/POST /v1/me/offers/{id}/accept`
- `GET /v1/enrolments` · `GET /v1/cohorts/{id}/qcto-enrolment-list`
- `GET/POST /v1/me/consents`

## 5. Performance, security and compliance

- **Performance:**
  - Applications board p95 under 150 ms at 2,000 applications per intake.
  - Document quality checks run on the phone first and are confirmed on the server in a queue.
- **Security:**
  - The public application form has abuse protection (rate limits, honeypot).
  - Documents are virus-scanned (Sprint 2 files) and visible only to the applicant and staff with `learners.view`.
  - Identity-check results are stored as a summary, never the raw Home Affairs record.
- **POPIA:**
  - The purpose is shown at each collection point.
  - Applicants who are not enrolled are deleted after a set period (e.g. 12 months, a setting).
  - Under-18s need guardian consent.

## 6. Out of scope

- Classes, attendance and course content (Sprint 5).
- The QCTO export file and EISA registration (Sprint 8).
- Fees and payments by learners (see §9).

## 7. Definition of done

- The demo script runs end to end on a phone build or the web.
- Tests for every rule (certification age, ID match, guardian signature, access rule, offer expiry); isolation tests for the new tables; axe audit clean.
- CI green, merged, close report, sprint sheet updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Poor phone cameras and lighting | On-phone quality check with live guidance; fall back to upload from the gallery |
| QCTO field list changes | Fields are data, checked against the published specification; the export is in Sprint 8 |
| Identity-check cost | Optional; off-peak batching (R1) by default; real-time only when the provider asks |
| Year-end break | Applications and documents first, then offers and enrolment, then the identity connector |

## 9. Decisions (approved 23 Nov 2026)

1. **Certified-copy age:** recommend a default of **3 months** (what most institutions ask), editable per provider and per document type. A provider can set 6 months to match government practice.
2. **Home Affairs identity check:** recommend building it as an **optional connector**, off by default, with batched off-peak checks to keep the cost near R1 each. The JV picks the reseller; we can start with one that has a sandbox.
3. **Learner fees:** recommend **no payments from learners in Sprint 4**: most are funded. Record the funding source now; card or EFT payments for self-funded learners can use the Sprint 2 gateways in a later sprint.
4. **Under-18 learners:** recommend a **guardian co-signature** on the enrolment agreement and guardian consent under POPIA.
5. **QCTO enrolment deadline:** recommend a default of **21 days from the training start**, to be confirmed with the QCTO and TYM, and editable.
