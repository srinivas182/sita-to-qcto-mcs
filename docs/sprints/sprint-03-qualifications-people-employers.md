# Sprint 3 — Qualifications, people and employers

**Plan dates:** 21 Dec 2026 – 1 Jan 2027 (Phase 1 – SaaS compliance core).
**Status:** draft for approval. Questions are in §10.

**Goal (from the sprint plan):** *Qualifications, people and employers set up.*

**Demo at the end:** *A cohort is set up against a real pilot qualification.*
1. The Super Admin publishes a real occupational qualification to the shared catalogue, with its knowledge, practical and work-experience modules, and a legacy qualification with its last enrolment date.
2. The provider adds the qualification to a centre's accreditation scope.
3. The owner invites a facilitator, an assessor and a moderator. Each records their registration, body, scope and expiry. The platform refuses to assign the assessor to a qualification outside their scope.
4. The provider adds a host employer with two workplace sites, its SETA workplace approval, and three mentors.
5. The provider sends the three-way workplace agreement. The employer and the provider sign on their phones with a one-time PIN, and the signed PDF carries a verification page.
6. The provider creates a cohort for the qualification at the centre, with placements at the employer within the mentors' capacity.
7. The learner opens the app and sees their profile. In 3D, the simulator 1 environment and assets are in place.

---

## 1. Where the market stands (Nov 2026)

**Regulation drives the design:**
- **Three components.** QCTO occupational qualifications are built from three parts:
  - **Knowledge modules (KM)**
  - **Practical skill modules (PM)**
  - **Work experience modules (WM)**

  Each has a code such as `351201001-WM-01`, an NQF level and credits. The curriculum template also sets accreditation criteria per module and workplace-approval criteria per work-experience module.
- **Legacy cut-over.**
  - In June 2026 the Minister announced that 630 of 1,475 pre-2009 qualifications get enrolment extensions; the rest were deregistered.
  - Legacy qualifications get 6–24 months, regulatory unit standards up to 36 months, and trades not yet registered up to 12 months.
  - 948 occupational qualifications and part-qualifications are registered.
  - So every provider runs **both tracks at once**, each with its own deadlines.
- **Workplaces.**
  - Employers that host work experience are not accredited. SETAs approve workplaces.
  - Providers must have agreements with workplaces for the work-experience modules.
  - One sector's published rule is one mentor per 10 learners, or as the qualification states. It needs a mentor matrix (name, years of experience, designation, qualification) and monthly mentor contact.
- **People.**
  - Assessors, moderators, facilitators and RPL advisors must be registered with the relevant QCTO-delegated quality assurance body (often a SETA).
  - Assessor registration comes before moderator registration.
  - Registrations expire and have a scope.
- **Signatures.**
  - ECTA accepts ordinary electronic signatures unless a law requires a signature and names no type. Then an *advanced* electronic signature from an accredited provider is needed.
  - Where an ordinary e-signature is used, the party relying on it must prove it is valid, so the evidence trail matters.

| Area | Leaders | What they do well (we adopt) | Their weakness here | Our extra |
|---|---|---|---|---|
| Qualification set-up | aXcelerate (AU, 1,300+ organisations); skillSYMS and other SA systems | Courses broken into units and assessments; assessment mapping; SA systems claim QCTO occupational certificates, qualifications and part-qualifications | Each provider keys in its own qualifications; no module codes or credit rules; no legacy deadlines | **One shared, curated catalogue** (Super Admin) of occupational and legacy qualifications with KM/PM/WM modules, codes, NQF, credits, EISA and AQP. Providers *adopt*, never re-type. **Legacy dates enforced**: enrolment closes on the gazetted date, with the occupational successor suggested |
| Accreditation scope | SA systems (claim-your-profile from the QCTO provider list) | Pre-loaded accreditations | Scope is a document, not a rule | Centre scope **links to catalogue entries**. Cohorts and assessor assignments are **refused outside scope** |
| Staff credentials | aXcelerate trainer/assessor competency matrix | Matrix of who can deliver and assess what | Built for AU standards; no SA registration bodies or expiry rules | **Credential register**: role (facilitator, assessor, moderator, RPL advisor, mentor), body (SETA/QAC), number, scope, expiry, certificate upload. Moderator needs an assessor registration first. **90/60/30-day expiry alerts** (re-uses the centres watcher). Assignment checks |
| Host employers | aXcelerate Work-Based Learning: placements with host employer, supervisors, schedule and digital logbook; supervisors approve in an app; employer ratings | Placement record (employer, supervisors, schedule, logbook); supervisor app | No SETA workplace approval, no mentor-ratio capacity | **Employers and sites** with SETA workplace approval (number, expiry, approved qualifications), GPS, and **mentor matrix**. **Capacity** = mentors × ratio (1:10 by default, or the qualification's own), shown before placing learners |
| Agreements | aXcelerate Agreements (enrolment terms, employer agreements, placement documents); DocuSign / Adobe Sign | Template agreements, tracked status | Generic e-signature; foreign data storage; email-only signing (weak in townships); evidence left to the provider | **Three-way workplace agreement** signed on the phone: identity confirmed by **one-time PIN** (WhatsApp/SMS), each signature recorded with time, IP and device, document **SHA-256 sealed**, and a final PDF with a **verification page and QR**. Stored in SA. Advanced e-signature (accredited provider) can plug in where a law requires it (§9) |
| People data | All | Profiles, bulk import | Duplicate learners across providers; ID numbers mistyped | SA ID **checksum and date-of-birth/gender cross-check** on entry; identity stored hashed + encrypted (Sprint 1); **bulk CSV import with a dry-run report** |
| Cohorts | aXcelerate classes; SA LMSs | Intakes with dates and enrolments | No checks against scope, legacy dates, workplace capacity or staff credentials | **Cohort readiness check** before it opens: qualification active (legacy date), centre in scope, staff credentials valid and in scope, enough approved workplace capacity, agreements signed |

## 2. Scope

### 2.1 Backend (`apps/api`)

**New module `Qualifications`.** The catalogue is platform-owned and readable by every provider; adoption is per provider.
- **Qualification fields:**
  - type (occupational qualification, part-qualification, skills programme, legacy qualification, legacy unit-standard-based skills programme)
  - SAQA ID, curriculum code, title, NQF level, credits
  - QCTO / SETA quality partner, AQP and EISA (yes/no)
  - status, registration end, last enrolment date, last achievement date
  - successor (legacy → occupational)
- **Modules:** kind (KM/PM/WM), code, title, NQF level, credits, compulsory or elective, order. Work-experience modules carry workplace-approval criteria (text).
- **Credit rule check:** the totals match the qualification. Each component is at least 20 % where the curriculum says so (warning only).
- **Import:** an Excel template plus validation. The Super Admin pastes from the curriculum document. Live SAQA lookup is not automated (no public API); see §10.
- **Provider adoption:** `tenant_qualifications` holds active, delivery modes, the provider's own notes and its programme price.

**Centres.** `centre_qualifications` replaces the scope SAQA IDs, with accreditation number and expiry per qualification. Data migrates from the current `scope` JSON.

**People** (extends `Identity` and `Access`):
- Profile fields: SA ID with checksum and DOB/gender cross-check, passport for non-SA, address, emergency contact, disability (optional, POPIA special information with explicit consent) and race/gender for SETA equity reporting (optional, consented, encrypted).
- **Credential register** (`staff_credentials`):
  - Fields: role, body, registration number, scope (catalogue links), start and expiry, certificate file, verified by / verified at.
  - Expiry alerts.
  - Rules:
    - A moderator credential needs an assessor credential for the same scope.
    - Assignments check that the credential is valid and in scope.
- **Per-provider custom roles** (carried from Sprints 1 and 2): owner-defined roles built from the permission catalogue. They can never exceed the owner's own permissions.
- **Bulk import:** CSV, a dry run with a row-level report, then a commit. Invitations go out by email/WhatsApp.

**New module `Employers`:**
- **Employers:** legal name, CIPC, SETA, levy number, B-BBEE level (optional), contacts.
- **Sites:** address, GPS, SETA workplace approval (number, expiry, approved qualifications or work-experience modules), status. Expiry alerts.
- **Mentors:** person (may sign in on the phone), years of experience, designation, qualification level, sites. Mentor ratio per site and qualification (default 1:10). Capacity is calculated.

**New module `Agreements`:**
- **Templates:** a tripartite workplace agreement, plus a provider–employer MoU, versioned, with merge fields.
- **Signing flow:**
  - Parties and order.
  - Identity by one-time PIN to the signer's phone (the Messaging module) or a signed-in session.
  - Typed or drawn signature.
- **Evidence** for each signature: time, IP, device, PIN channel, document hash.
- **Final PDF:** the signatures plus a verification page with a QR code to `/verify/{code}`, sealed (SHA-256) and stored in SA.
- **Lifecycle:** reminders, expiry, and a cancel or void reason.
- **Signer interface** (`SignatureProvider`): `simple` (ours) now. An accredited advanced e-signature provider plugs in later (§9).

**Cohorts** (in `Qualifications`; enrolment itself is Sprint 4):
- **Fields:** qualification, centre(s), intake and planned end, mode, capacity, facilitator/assessor/moderator assignments, workplace placements (site, mentor, planned learners).
- **Readiness check:** a list of pass, warn or fail items. A cohort can open only when no item fails.

### 2.2 Web (`apps/web`)
- **Super Admin:**
  - Catalogue (list, detail with modules, import, legacy dates and successor).
  - "Qualifications closing soon" panel.
- **Provider:**
  - Programmes (adopt from the catalogue).
  - Centres: scope per qualification.
  - People: profile, credentials, import.
  - Roles: custom roles.
  - Employers: sites, approval, mentors, capacity.
  - Agreements: send, track, download.
  - Cohorts: wizard with the live readiness check.
- **Public:** the `/sign/{token}` signing page (phone-first), and `/verify/{code}` for agreements.

### 2.3 Mobile (`apps/mobile`)
- Learner profile: details, documents, consent records; editable where the provider allows.
- Mentor sign-in, and a "My learners" placeholder (the logbook comes in Sprint 7).
- Sign an agreement in the app with a PIN; push "you have a document to sign".

### 2.4 3D / simulators
- **Simulator 1 environment and assets**, to the specification signed off in the Sprint 2 workshop. The workshop is *external* and must happen first.

## 3. Data model (new / changed)

| Table | Purpose / key columns |
|---|---|
| `qualifications` (platform) | type, saqa_id, curriculum_code, title, nqf_level, credits, quality_partner, aqp, eisa, status, registration_end, last_enrolment_on, last_achievement_on, successor_id |
| `qualification_modules` (platform) | qualification, kind (KM/PM/WM), code, title, nqf_level, credits, compulsory, sort, workplace_criteria |
| `tenant_qualifications` | tenant, qualification, active, modes, price_cents, notes |
| `centre_qualifications` | centre, qualification, accreditation_number, expires_on, alerted_for |
| `staff_credentials` | user, role, body, number, qualification scope (json of ids), starts_on, expires_on, file, verified_by/at, alerted_for |
| `custom_roles` | tenant, name, permissions (json), based_on |
| `employers`, `employer_sites` | legal details, SETA, levy no.; site address, GPS, approval no./expiry/scope, status |
| `mentors` | employer, user, experience_years, designation, qualification_level, sites |
| `agreement_templates`, `agreements`, `agreement_signers` | version, body; parties, status, document hash, final file; signer, order, method, signed_at, ip, device, evidence |
| `cohorts`, `cohort_staff`, `cohort_placements` | qualification, centre, dates, capacity, status; assignments; site, mentor, planned learners |

Every tenant table gets `tenant_id` and forced RLS. Catalogue tables are readable by tenants and writable only by the platform (an RLS policy per command).

## 4. API (v1, additions)

- **Catalogue (Super Admin):**
  - `GET/POST/PATCH /v1/admin/qualifications` · `POST /v1/admin/qualifications/import`
  - `GET /v1/qualifications` (catalogue, read) · `POST /v1/programmes` (adopt) · `GET/PATCH /v1/programmes/{id}`
- **Centres:** `PUT /v1/centres/{id}/qualifications`
- **People:**
  - `GET/POST/PATCH /v1/people/{id}/credentials`
  - `POST /v1/people/import` (dry run, then commit)
  - `GET/POST/PATCH /v1/roles/custom`
- **Employers:** `GET/POST/PATCH /v1/employers` · `…/sites` · `…/mentors`
- **Agreements:**
  - `POST /v1/agreements` · `POST /v1/agreements/{id}/send` · `GET /v1/agreements/{id}`
  - `GET/POST /v1/sign/{token}` (public) · `POST /v1/sign/{token}/pin` · `GET /v1/verify/{code}` (public)
- **Cohorts:** `GET/POST/PATCH /v1/cohorts` · `GET /v1/cohorts/{id}/readiness`

## 5. Performance, security and compliance

- **Budgets:**
  - Unchanged.
  - The catalogue list is p95 under 100 ms, cached per version.
  - The readiness check is under 300 ms.
- **POPIA:**
  - Race, gender and disability are optional. They are collected only with explicit consent, for SETA equity reporting.
  - They are encrypted, and excluded from exports unless the report needs them.
- **Signing:**
  - Signing links are single-use tokens. PINs expire in 10 minutes.
  - Documents are hashed before and after signing, and every change is audited.
  - Agreements cannot be changed once sent; changes create a new version.
- **Imports** never write until the dry run is accepted.

## 6. Out of scope

- Learner applications and enrolment (Sprint 4).
- Logbooks and mentor sign-off (Sprint 7).
- QCTO/SETA MIS exports (later in Phase 1).
- Live SAQA or QCTO register lookups (no public API; see §10).

## 7. Definition of done

- The demo script runs end to end.
- A real pilot qualification and one legacy qualification are loaded.
- Every item is built with tests, isolation tests cover the new tables, and the axe audit is clean.
- CI is green and everything is merged.
- Close report written; sprint sheet updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Curriculum data is long and hand-copied | Import template with total and code checks; TYM checks the pilot qualification |
| Legacy extension lists change by gazette | Dates are data the Super Admin edits, with a history; the "closing soon" panel |
| Signature disputes | Strong evidence trail (PIN, device, hash, verification page); an advanced e-signature provider can be added |
| Two weeks over the year-end break | Catalogue and people first; agreements and cohorts second; 3D runs in parallel |

## 9. Decisions proposed

1. **Signatures:** our own ordinary e-signature, with PIN identity and a sealed evidence trail, for workplace agreements and MoUs. Add an accredited **advanced** e-signature provider later only if a SETA or law requires one.
2. **Catalogue ownership:** a single curated catalogue owned by the platform. Providers adopt from it and cannot edit it, but can add notes and prices.
3. **Mentor ratio:** 1:10 by default, editable per qualification and site.
4. **Equity fields** (race, gender, disability): optional, with explicit consent, encrypted.

## 10. Questions for you / TYM

1. Which **pilot qualification** (curriculum code) for the demo, and which **legacy** one does TYM still enrol?
2. Do any of TYM's SETAs **require an advanced electronic signature** on workplace agreements? If so, which provider do you prefer, and would you accept its per-signature cost?
3. Who keys in the **catalogue**: our team from the QCTO curriculum documents, or TYM's quality manager?
4. Is the **simulator 1 workshop** date set?
