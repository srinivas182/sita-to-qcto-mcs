# Sprint 8: Zero-rejection engine and EISA registration

**Plan dates:** 1 – 12 Mar 2027 (Phase 1 – SaaS compliance core).
**Status:** draft for approval (decisions in §9).

**Goal (from the sprint plan):** *Zero-rejection engine and EISA registration.*

**Demo at the end:** *The provider sends the QCTO file with no rejections, and registers ready learners for the EISA before the closing date.*
1. The provider runs the check on the cohort's QCTO file (Learner Enrolment and Readiness for EISA). Every field is checked against the QCTO data load specification and the provider's accreditation, and each problem links to the screen where it is fixed. The file can be exported only when there are no errors.
2. The QCTO's answer can be loaded back: rejected learners show as errors with the QCTO's reason.
3. The EISA calendar shows each qualification's EISA dates and registration closing dates, with a countdown per cohort. The provider is warned while there is still time.
4. For each ready learner the provider records the assessment centre and the foundational learning result (with evidence). The learner signs the EISA declaration in the app.
5. The provider downloads each learner's EISA registration pack (ID copy, Statement of Results, signed declaration, proof of the QCTO submission) and records the registration.

---

## 1. Where the market stands (Nov 2026)

**Regulation:**
- The file is named `LEISAyyyymmdd-SDP/AC name` and emailed to the QCTO. Learner data is submitted within 21 days of enrolment, and again for EISA readiness ([QCTO data load specification](https://QCTO.ORG.ZA/assets/data-loading-specification-document.pdf)).
- Before submitting, the provider must apply its accreditation status: the accreditation period, and the qualifications it is accredited for with their start and end dates (same specification).
- The specification's code lists include:
  - AK Statement of Results status (01 issued, 02 not yet);
  - AL issue date, required once the learner is competent in all modules and not later than the EISA date;
  - AM assessment centre accreditation number;
  - AN readiness type;
  - AO foundational learning (FLC), which must contain data;
  - AP the FLC Statement of Results number.
- SDPs find the EISA dates and registration closing dates in the EISA plan on the QCTO website, and no late registrations are allowed ([QCTO FAQs](https://www.qcto.org.za/assets/QCTO_FAQs_V6.pdf)).
- Learners enrolled after a closing date go to the next scheduled EISA ([QCTO notice](https://aqp.inseta.org.za/images/QCTO%20Notice%20to%20all%20QCTO%20Skills%20Development%20Providers.pdf)).
- Quality partners ask for a pack per learner: an ID copy, the SDP Statement of Results on the QCTO template, a learner declaration or readiness form, and proof of the LEISA submission ([INSETA EISA notice](https://aqp.inseta.org.za/images/EISA%20Notice.pdf)).

| Area | Leaders | What they do well (we adopt) | Their weakness here | Our extra |
|---|---|---|---|---|
| Validate before you send | UK Submit Learner Data: published validation rules and a report of rule violations before submission ([DfE](https://guidance.submit-learner-data.service.gov.uk/24-25/validation-rules/rule/Entity_1)); NZ SDR validation rules with error codes ([TEC](https://tec.govt.nz/assets/Forms-templates-and-guides/Data-system-refresh/Validation-Rules-SDR-and-IND-2025-v1.0.docx)) | A versioned rule set with codes; errors versus warnings; the whole file refused if it fails | Not South African; providers still find errors at the regulator | **The QCTO rules run in the platform as data is captured**, not only at export; each error links to the fix; the file can't leave with errors |
| QCTO process today | Spreadsheet template and email | — | Errors are found after submission; no record of what was sent | **Submission log** with what was sent, and the QCTO's response loaded back |
| EISA calendar | QCTO and quality partners publish EISA plans as PDFs ([QCTO 2025 plan](https://qcto.org.za/assets/2025_QP_Planned_EISA_dates.pdf)) | Dates and closing dates per qualification | PDFs; providers miss closing dates | **Calendar per qualification**, countdown per cohort, alerts before the closing date |
| Registration pack | Paper folders per learner | — | Assembled by hand at the deadline | **Pack per learner made from the platform**: ID copy, Statement of Results, declaration signed in the app, proof of submission |
| South African training software | Wemasoft, aNewSpring (assessments, moderation) | — | No QCTO file validation or EISA registration | All of the above in one place |

## 2. Scope

### 2.1 Backend (`apps/api`)
- **Rule engine** (versioned, dated):
  - field rules from the specification: required, size, type, code lists, date formats;
  - cross-field rules: AL once all modules are competent and not later than the EISA date; AM when registering; AO and AP must contain data; ID number checksum and date of birth agree;
  - accreditation rules: the SDP is accredited for the qualification on the training dates, and the accreditation hasn't ended.
- Each rule has a code, a message and a fix link. Rules are checked as data is saved (warnings in the forms) and on the whole file. Export is refused with errors.
- **QCTO response import:** load the QCTO's reply (rejected rows and reasons) back onto learners.
- **EISA calendar:** qualifications × EISA dates × closing dates, kept by the platform and overridable by the provider; countdown per cohort; alerts at 30, 14 and 3 days.
- **EISA registration:**
  - assessment centre (accreditation number, with the centre's own accreditation dates checked);
  - foundational learning result and evidence;
  - the learner's declaration, signed in the app through the Sprint 4 agreements engine;
  - the registration record;
  - the pack as a ZIP per learner and per cohort.

### 2.2 Web (`apps/web`)
- **QCTO file:** a check screen with errors and warnings per learner, grouped by fix; export; submission log; loading the QCTO's response.
- **EISA:** calendar; cohort registration screen; packs.

### 2.3 Mobile (`apps/mobile`)
- **Learner:** sign the EISA declaration; see their EISA date and venue.
- **Learner profile fields for the QCTO file** (carried from Sprint 4), with the same rule checks.

## 3. Data model (new)

| Table | Key fields |
|---|---|
| `qcto_rules` (code) | version, column, code, severity, message, fix |
| `qcto_findings` | cohort, learner, rule, value, found_at, resolved_at |
| `qcto_responses` | submission, file, rows (json), loaded_at |
| `eisa_dates` | qualification, quality partner, eisa_on, closes_on, source, tenant override |
| `eisa_registrations` | enrolment, eisa_date, assessment centre number, flc code, flc number, evidence file, declaration agreement, status, registered_at |

## 4. API (v1, additions)

- `GET /v1/cohorts/{id}/qcto-check` · `POST /v1/qcto-submissions/{id}/response`
- `GET/PUT /v1/eisa-dates` · `GET /v1/cohorts/{id}/eisa`
- `POST /v1/enrolments/{id}/eisa-registration` · `GET /v1/cohorts/{id}/eisa-packs`

## 5. Performance, security and compliance

- The check of a 300-learner cohort completes in under 2 seconds.
- Packs hold ID copies: they are made on request, the download is audited, and links are short-lived.
- The rule set is versioned. When the QCTO changes the specification, a new version applies from its date.

## 6. Out of scope

- Submitting through a QCTO API: there is none. Email stays, with the log.
- Quality partner portals (we export what they ask for).
- Certification after the EISA (Sprint 9 or later).

## 7. Definition of done

- The demo script runs end to end.
- Tests for every rule against the specification's examples; export refused with errors; response import; calendar alerts; registration after the closing date refused; pack contents.
- axe clean; CI green; merged; close report; sprint sheet updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| The QCTO changes the template or code lists | Versioned rules; a platform admin screen to add a version |
| EISA dates published late or changed | Provider override with the source noted; alerts on change |
| Foundational learning evidence missing for older learners | A clear blocker per learner; RPL route (AO 02) supported |
| Learners without the app for the declaration | Sign by SMS link (Sprint 4 signing) or a scanned paper form |

## 9. Decisions (for approval)

1. **Export with errors:** recommend **refusing export while any error remains**. Warnings may be acknowledged and sent.
2. **EISA dates:** recommend the **platform keeps the calendar** from the quality partners' published plans, and **providers may override** a date with the source noted.
3. **Declaration:** recommend the learner **signs the EISA declaration in the app** (or by SMS link), so the pack is complete without paper.
4. **Foundational learning:** recommend **evidence is required** for AO codes 01, 03 and 08 (certificate, school results, N3), and the RPL date for 02.
5. **Closing dates:** recommend a **hard stop after the closing date** (the QCTO allows no late registrations), with the learner moved to the next EISA date.
