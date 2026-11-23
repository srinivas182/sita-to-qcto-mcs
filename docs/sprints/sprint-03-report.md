# Sprint 3 — Qualifications, people and employers: close report

**Status:** Build complete. Parts 3a to 3f merged with CI green. Items that wait on
documents, accounts or people outside the code are marked *external*.

**Pull requests:**
- #13 catalogue
- #14 people, registrations and custom roles
- #15 employers
- #16 agreements and e-signing
- #17 cohorts
- #18 mobile and close (this report)

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. Super Admin publishes a real occupational qualification with KM/PM/WM modules, and a legacy one with its last enrolment date | ✅ legacy; ⏳ *external* for the occupational | **GETC: Construction (49411)** is seeded complete from SAQA: 106 unit standards, credit rules, enrolment closed on 30 Jun 2026. **Bricklayer (93627)** is in the catalogue as a draft: its module codes are in the QCTO curriculum document, which isn't public, and nothing is guessed. The editor and module import are tested (`CatalogueTest`) |
| 2. Provider adds the qualification to a centre's scope | ✅ | Centre scope checklist; cohorts and staff assignments are refused outside scope |
| 3. Owner invites facilitator, assessor, moderator; registrations with body, scope and expiry; assessor refused outside scope | ✅ | `CredentialService::eligibility`; moderator needs a valid assessor registration; 90/60/30-day expiry alerts |
| 4. Host employer with two sites, SETA workplace approval, three mentors | ✅ | Demo: Mabaso Builders, Soweto yard (approved, 2 mentors, 20 places) and Midrand (approval ending in 45 days, 1 mentor) |
| 5. Three-way agreement signed on phones with a one-time PIN; signed PDF with a verification page | ✅ | PIN by WhatsApp, then SMS, then email; sealed PDF with evidence page and QR; public `/verify/{code}` checks a copy in the browser. In-app signing with a push notice (3f) |
| 6. Cohort for the qualification at the centre, placements within mentors' capacity | ✅ | Readiness check; opens only when nothing fails. The demo cohort is for GETC, so it correctly **can't open**: enrolment closed on 30 Jun 2026. A cohort for Bricklayer opens once its curriculum is loaded (step 1). Tests open cohorts end to end |
| 7. Learner opens the app and sees their profile; simulator 1 environment and assets | ✅ app; ⏳ *external* 3D | Profile tab (details, emergency contact, equity consent with withdrawal), documents to sign, mentor's "My learners". **3D:** the build needs the simulator 1 spec from the workshop proposed for 3 Dec |

## 2. Scope checklist (spec §2)

### Backend
| Item | Status | Where |
|---|---|---|
| Shared catalogue: occupational and legacy qualifications, KM/PM/WM modules, codes, NQF, credits, EISA, AQP, dates; draft → published; suggestions from providers | ✅ | `modules/Qualifications`, ADR-014 |
| Programmes (provider adopts from catalogue), centre scope linked to catalogue, enrolment-closing alerts | ✅ | `ProgrammeService`, `qualifications:enrolment-alerts` |
| Staff invites, registrations (role, body, number, scope, expiry, letter), verification by someone else, expiry alerts | ✅ | `modules/People`, ADR-015 |
| Per-provider custom roles (carried from Sprint 1) | ✅ | `CustomRoleService` |
| Bulk people import with dry-run; SA ID checks; encrypted equity data with consent | ✅ | `PersonImport`, `ProfileService` |
| Employers, sites with SETA approval, mentors, capacity, approval alerts | ✅ | `modules/Employers`, ADR-016 |
| Agreements: versioned templates, merge fields, PIN signing, sealing, verification, reminders, expiry; LAWtrust behind a switch | ✅ (LAWtrust ⏳ *external* sandbox) | `modules/Agreements`, ADR-017 |
| Cohorts, staff, placements, readiness check, open/close, daily watch | ✅ | `modules/Cohorts`, ADR-018 |
| Signers linked to accounts; push "document to sign"; in-app signing; mentor view | ✅ 3f | `MyAgreementsController`, `MentoringController` |

### Web
| Item | Status |
|---|---|
| Super Admin catalogue editor and suggestion queue | ✅ |
| Provider: programmes, centre scope, registrations, custom roles, profile, employers, agreements and wording, cohorts with the live readiness panel | ✅ |
| Public: `/sign/{token}` (phone-first) and `/verify/{code}` | ✅ |

### Mobile
| Item | Status |
|---|---|
| Learner profile: details, emergency contact, equity consent and withdrawal | ✅ |
| Mentor sign-in and "My learners" placeholder | ✅ — mentors sign in with the Host employer role and are recognised by the link on their mentor record (see §7) |
| Sign an agreement in the app with a PIN; push "you have a document to sign"; tapping the notice opens it | ✅ — the route is checked against a strict pattern, so a notice can't send the app anywhere else |

### 3D / simulators
| Item | Status |
|---|---|
| Simulator 1 environment and assets | ⏳ *external* — waiting on the specification workshop with TYM's subject experts (proposed 3 Dec, backup 8 Dec) |

## 3. Performance (spec §5)

Measured in-process on the 2-core sandbox, 40 runs after 5 warm-ups (10 for the PDF):

| Operation | p50 | p95 | Budget |
|---|---|---|---|
| Cohort readiness check | 14 ms | 18 ms | 300 ms (also enforced by a test) |
| Employer capacity and workplace criteria | 7 ms | 11 ms | — |
| Public verification lookup | 2 ms | 3 ms | — |
| Sealed agreement PDF (text, evidence page, QR) | 175 ms | 188 ms | 2 s |

- **Web:** initial JavaScript is 120.7 KB gzip of a 160 KB budget. Every new page loads on demand.

## 4. Security and POPIA

- **Row-level security** on every new tenant table. The catalogue has separate read and write policies, so providers can read it but never change it.
- **Signing links:**
  - 48 random characters, stored only as a hash.
  - Resending invalidates the old link, and completion issues a fresh link for each signer's copy.
  - PINs: valid 10 minutes, at most 5 wrong tries, at most 8 PINs per signer, with a pause between sends.
  - The typed name must match the signer.
  - Evidence kept per signature: time, IP, device, PIN channel and masked destination, the text fingerprint, and whether signed by link or in the app.
- **Verification page:** shows who signed and when, but never the document. A copy is checked on the person's own device.
- **Linking signers to accounts never guesses:** if two members match a phone or email, the signer stays unlinked.
- **Equity data:** encrypted, collected only with consent, withdrawable from the app.
- **Mentors:** see only workplaces they are linked to and the cohorts planned there. No learner names until enrolment.

## 5. Accessibility

axe (WCAG 2.2 AA): **52 screens, 0 violations**, in CI. New this sprint (25 screens):
- catalogue
- programmes
- registrations and roles
- profile
- employers and their dialogs
- agreements, wording, the send dialog and the editor
- the public sign and verify pages
- cohorts and the plan dialog

**Fixed in 3e:** red and green status colours on the employer, agreement and cohort pages weren't showing, because they used colour names the theme doesn't define.

## 6. Quality

| | Sprint 2 | Sprint 3 |
|---|---|---|
| API tests (Pest) | 246 | **295** (1,980 assertions) |
| Mobile tests | 15 | 17 |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| ADRs | 13 | 18 (ADR-014 to 018) |

## 7. Changes from the spec (for your approval)

1. **Cohorts are a module of their own**, not part of Qualifications. They draw on four modules, and a separate module avoids a circular dependency.
2. **Mentors use the existing Host employer role.** The app recognises a mentor by the link on their mentor record. The 17 standard roles stay unchanged.
3. **The cohort check keeps running after opening.** Breaking changes are refused, and a daily check emails the owner when something lapses. This is an addition.
4. **API names differ slightly from §4:**
   - `POST /v1/agreements` sends immediately; there is no separate `/send`.
   - The PIN is requested with `POST /v1/sign/{token}/code`.
   - In-app signing has its own endpoints: `/v1/me/agreements…`.
5. **Standard signatures are evidence-based**, not a cryptographic signature inside the PDF (valid under ECTA s13(3)). LAWtrust covers the cases that need an advanced signature.

## 8. Carried into Sprint 4

- **Per-learner checks in the readiness check:** identity document on file, workplace agreement signed before placement.
- **Learner names** in the mentor's "My learners".
- **Visual check of the new mobile screens on a phone:** needs the EAS build (*external*). They are type-checked, linted, bundled, and their logic is unit-tested.
- **Loading Bricklayer (93627)** once TYM sends the curriculum document.

## 9. Needed from you / TYM

- Approve this report and the Sprint 4 spec (`sprint-04-applications-enrolment.md`).
- **TYM:**
  - The QCTO curriculum document for Bricklayer (93627), and whether its enrolment is extended past 30 Dec 2026.
  - Their accreditation for 65409.
  - Confirm the simulator 1 workshop (3 Dec).
- **Accounts:**
  - A LAWtrust sandbox, if any SETA needs advanced signatures.
  - The items still open from Sprint 2: Meta templates, gateway sandbox keys, Expo/Apple/Google accounts.
