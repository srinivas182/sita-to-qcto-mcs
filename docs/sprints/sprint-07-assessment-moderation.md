# Sprint 7: Assessment through to Statement of Results

**Plan dates:** 15 – 26 Feb 2027 (Phase 1 – SaaS compliance core).
**Status:** draft for approval (decisions in §9).

**Goal (from the sprint plan):** *Assessment through to Statement of Results.*

**Demo at the end:** *An assessor marks a practical on site from a phone, a moderator samples it, and the provider issues the learner's Statement of Results.*
1. The provider sets the summative assessments for each module (knowledge, practical, workplace): instrument, criteria and how a learner is found competent, taken from the QAS.
2. A registered assessor runs the assessment: a knowledge test online, or a practical observation on the phone with a criteria checklist and photo or video evidence, offline if needed. They record **Competent** or **Not yet competent** with feedback.
3. The learner sees the result and feedback. Not yet competent leads to remediation and a reassessment. The learner can appeal.
4. A registered moderator gets a sample by the provider's policy (always including every Not yet competent and every appeal), agrees or overturns each decision, and signs a moderation report.
5. For the workplace module, the checked logbook sign-off from Sprint 6 is the evidence.
6. The provider sees, per learner, which modules are final, issues the **Statement of Results** (knowledge, practical and work experience modules) as a signed PDF, and the achievement data is ready for the QCTO.

---

## 1. Where the market stands (Nov 2026)

**Regulation:**
- Internal summative assessment is the provider's job. Results are formally recorded **per module** for the Statement of Results, in any format (workplace tasks, practical demonstrations, assignments or tests, projects). The provider must run internal quality assurance so assessments are fit for purpose ([QCTO quick guide](https://QCTO.ORG.ZA/assets/quick-guide---implementation-of-occupational-qualifications.pdf)).
- The provider issues the Statement of Results once a learner completes all modules of all components. It is what makes the learner eligible for the EISA, and the provider submits it to the QCTO before the EISA (same guide). Issuing it is this sprint; EISA registration is Sprint 8.
- The QAS template places internal assessment on the accredited provider: plan and develop, then conduct, administer and manage it ([QCTO QAS template](https://www.qcto.org.za/assets/qualification-assessment-specification-(qas)-document-template-v1.2-01092023.docx)).
- Moderation by registered moderators is expected. SETA verification moderators ask whether learners had remediation opportunities in line with the provider's policies ([INSETA verification report](https://inseta.org.za/wp-content/uploads/2023/12/Verification-Moderator-Report-Nov-2023.pdf)). Provider policies often cite external moderation on a 25% random sample ([example policy](https://sageu.com/legal/africa/policies/14_Moderation_Policy.pdf)).

| Area | Leaders | What they do well (we adopt) | Their weakness here | Our extra |
|---|---|---|---|---|
| Sampling | OneFile: a sampling policy sets the share of each assessor's learners and units to sample, the methods to include and the split between summative and interim; a "compliant" flag shows whether the plan meets it ([OneFile](https://support.onefile.co.uk/support/solutions/articles/76000021590-sampling-policy-overview)). Smart Assessor (OneAdvanced): sampling groups, auto-planned summative samples, export ([Smart Assessor](https://smartassessor.helpdocs.io/article/g9f2ez5w94-iqa-sample-plan-management)) | A policy per provider; a compliance flag; a list of everything waiting | Random or planned sampling only; built for UK awarding bodies | **Risk-based sample by default:** every Not yet competent, every appeal, a new assessor's first decisions, plus a random share. The compliance flag is on the moderator's queue |
| Moderation decision | OneFile: the moderator accepts or rejects, can override criteria, records actions for the assessor, and signs ([OneFile](https://support.onefile.co.uk/en/support/solutions/articles/76000035128)) | Agree / overturn with reasons and actions; signature | Decisions live in the e-portfolio; no report in the form SETA verifiers ask for | **Moderation report PDF** per cohort and module in the verifier's shape, signed in the app |
| Readiness | Ecordia: a unit completion declaration by assessor and candidate creates the IQA task ([Ecordia](https://ecordiasupport.freshdesk.com/support/solutions/articles/206000055427-qa-sampling-plan)); Smart Assessor: gateway readiness checklist ([OneAdvanced](https://platform.softwareone.com/product/oneadvanced-eportfolio/PCP-3958-5793)) | Completion triggers QA; a readiness checklist | UK end-point assessment, not QCTO EISA | **Statement of Results readiness per learner**: every module final (moderated or not sampled), attendance and logbook complete |
| South African tools | Wemasoft (online assessments and PoE, remediation, online moderation) ([Wemasoft](https://wemasoft.co.za/lms)); aNewSpring with a SETA moderation function ([ITEdge](https://itedgenews.africa/new-leaf-technologies-adds-seta-compliant-tech-game-changer-for-south-africas-learning-and-development-professionals)) | Remediation and moderation online | Desktop-first; practical observations still on paper; assessor registration not checked at the moment of the decision | **Observation on the phone, offline**, with criteria, photo or video; **assessor and moderator registration checked when they decide** (Sprint 3 credentials) |
| Integrity | Signed forms | — | A result can be changed quietly | **Each decision is fingerprinted** like the logbook; a result changes only by a new, linked decision (reassessment, moderation or appeal) |

## 2. Scope

### 2.1 Backend (`apps/api`)
- **New module `Assessment`:**
  - **Instruments per module:** kind (knowledge, practical, workplace), method (test, observation, project, logbook), criteria (from the QAS), and the competence rule (all criteria met, or a pass mark for tests). A knowledge test can reuse the Sprint 5 task engine with a summative flag.
  - **Results:** learner, module, instrument, attempt, assessor, outcome (competent, not yet competent), criteria checks, evidence (files), feedback, decided at, fingerprint. Only registered assessors for the qualification can decide. Workplace module results come from the checked logbook sign-off.
  - **Remediation and reassessment:** after Not yet competent, a remediation note and a new attempt, up to the provider's limit.
  - **Moderation:** policy (random share, always include rules), sample per cohort and module, decisions (agree, overturn with a new outcome), actions for the assessor, a signed report.
  - **Appeals:** the learner appeals within the policy window; a moderator other than the assessor decides; outcome recorded and the learner told.
  - **Readiness:** per learner, which modules are final, and whether they are ready for the Statement of Results.
  - **Statement of Results:** issued per learner once every module is final: module results (with the Sprint 6 Statement of Work Experience), signed by the provider, fingerprinted PDF with a public verification page (as for agreements), and the achievement rows for the QCTO file.

### 2.2 Web (`apps/web`)
- **Provider:** instruments per module; assessment schedule per cohort; results grid (learner × module); moderation policy; moderation queue and report; appeals; readiness list; issue and download Statements of Results.
- **Assessor:** my assessments to do; record a decision with criteria and evidence.
- **Moderator:** my sample, decisions, report signing.
- **Learner:** my results and feedback; appeal; my Statement of Results.

### 2.3 Mobile (`apps/mobile`)
- **Assessor:** practical observation checklist, photo and video, offline, decided with a signature tick.
- **Learner:** results, feedback, remediation and reassessment dates, appeal.

### 2.4 3D / simulators
- Simulator 1 as practical evidence: depends on the simulator 1 specification (*external*).

## 3. Data model (new)

| Table | Key fields |
|---|---|
| `assessment_instruments` | module, kind, method, title, criteria (json), rule, task_id (knowledge tests) |
| `assessment_results` | learner, enrolment, module, instrument, attempt, assessor, outcome, criteria (json), file_ids, feedback, decided_at, hash, status (decided, moderated, appealed, final), replaces_id |
| `moderation_policies` | random_pct, always (json: not yet competent, appeals, new assessor first n), appeal_days, max_attempts |
| `moderation_samples` | result, moderator, reason (random, nyc, appeal, new assessor), decision (agree, overturn), new_outcome, actions, comment, decided_at |
| `moderation_reports` | cohort, module, moderator, findings, signed_at, file hash |
| `assessment_appeals` | result, learner, reason, lodged_at, decided_by, outcome, decided_at |
| `statements_of_results` | enrolment, number, issued_by, issued_at, modules (json snapshot), file hash, verification token, status (issued, withdrawn) |

All with `tenant_id` and forced RLS.

## 4. API (v1, additions)

- `GET/PUT /v1/modules/{id}/instruments`
- `GET /v1/cohorts/{id}/results` · `POST /v1/assessment-results` (assessor) · `POST /v1/assessment-results/{id}/reassess`
- `GET/PUT /v1/moderation-policy` · `GET /v1/me/moderation` · `POST /v1/moderation-samples/{id}/decide` · `POST /v1/cohorts/{id}/moderation-reports`
- `GET /v1/me/results` · `POST /v1/assessment-results/{id}/appeal` · `POST /v1/appeals/{id}/decide`
- `GET /v1/cohorts/{id}/readiness`
- `POST /v1/enrolments/{id}/statement-of-results` · `GET /v1/statements-of-results/{id}/pdf` · `GET /v1/public/sor/{token}` (verify)

## 5. Performance, security and compliance

- **Registration checks** at the moment of each decision: the assessor or moderator must be registered for the qualification on that date (Sprint 3 credentials). The moderator must not be the assessor.
- **Integrity:** results are never edited. A reassessment, moderation overturn or appeal outcome is a new, linked record. Fingerprints as in the logbook.
- **Offline observation:** queued like logbook entries; the assessor's registration is checked again when it arrives.
- **POPIA:** evidence photos and video are visible only to the assessor, moderator, provider staff with the right permission, and the learner.
- **Performance:** results grid for 300 learners × 20 modules in under 300 ms.

## 6. Out of scope

- EISA registration and the zero-rejection checks on the QCTO submission (Sprint 8).
- External moderation by the Quality Partner (they use their own systems); we export what they ask for.
- RPL (recognition of prior learning) assessments (later; same engine).

## 7. Definition of done

- The demo script runs end to end on the web and a phone build.
- Tests for:
  - registration checks at decision time;
  - competence rules;
  - reassessment limits;
  - sampling rules and policy compliance;
  - moderation overturn;
  - appeals (window, different moderator);
  - readiness;
  - Statement of Results issue, fingerprint and verification;
  - offline observation order.
- axe clean; CI green; merged; close report; sprint sheet updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| QAS and curricula not loaded (Bricklayer) | Instruments can be entered by hand; criteria import when TYM sends the documents (*external*) |
| Few registered moderators per provider | Allow a moderator from another centre of the same provider; flag when none is registered |
| Assessors used to paper | One-screen checklist on the phone; the paper form can be photographed as evidence |
| Video evidence size on low data | Upload on Wi-Fi option; low-data copy as in Sprint 5 |

## 9. Decisions (for approval)

1. **Outcomes:** recommend **Competent / Not yet competent per module**, with marks kept for tests only.
2. **Sampling:** recommend a **default policy of 25% random** per assessor, cohort and module, **plus every Not yet competent, every appeal and each new assessor's first five decisions**. The provider can change the share.
3. **Reassessment:** recommend **two reassessments (three attempts)** after remediation, which the provider can change.
4. **Appeals:** recommend learners may appeal **within 14 days**, decided by a **moderator other than the assessor**, with the outcome **within 21 days**.
5. **Facilitator as assessor:** recommend **allowed** (common in South Africa), but the moderator must not be the assessor, and those decisions are always in the sample for the first cohort.
