# Sprint 7: Assessment through to Statement of Results (close report)

**Status:** Build complete. Parts 7a to 7d merged with CI green. Items that wait on accounts, documents or people outside the code are marked *external*.

**Pull requests:**
- #34 summative assessment instruments and assessor decisions
- #35 moderation sampling, decisions, reports and appeals
- #36 Statement of Results
- #37 the app (assessor observation offline, learner results and appeals) and close (this report)

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. Provider sets the summative assessments per module | ✅ | Programmes → Assessments: method by module kind, criteria, pass mark for tests; the policy (attempts, sample, appeal windows) on the same page |
| 2. A registered assessor marks a practical on site from a phone, offline | ✅ | App → Assessments: criteria ticks, comments, photos; saved on the phone and sent when there is signal. Registration checked on the date assessed |
| 3. Learner sees result and feedback; Not yet competent → remediation and reassessment; appeal | ✅ | My results (web and app); attempts limited by policy; appeal within 14 days |
| 4. Moderator samples by policy (every Not yet competent and appeal), agrees or overturns, signs a report | ✅ | Risk-based sample at decision time; Moderation queue; report PDF per cohort and module |
| 5. Workplace module: checked logbook sign-off is the evidence | ✅ | A logbook instrument can only be Competent once the mentor's sign-off is checked |
| 6. Readiness and the Statement of Results; achievement data for the QCTO | ✅ | Cohort → Statements of Results: blockers in plain words, issue, withdraw, PDF, public check page; QCTO file AK/AL/AN filled |

Demo data:
- Sipho (sipho@demo.test) has assessed Bongani: the test is Competent and the practical is Not yet competent.
- Bongani has appealed the practical.
- Zanele (zanele@demo.test) has both decisions and the appeal to moderate.

## 2. Scope checklist (spec §2)

### Backend
| Item | Status | Where |
|---|---|---|
| Instruments per module; policy | ✅ | `modules/Assessment`, ADR-029 |
| Decisions: rules, registration at decision date, attempts, fingerprint, idempotent | ✅ | ADR-029 |
| Moderation: sampling, decisions, compliance, report | ✅ | ADR-030 |
| Appeals | ✅ | ADR-030 |
| Readiness and Statement of Results; verification; QCTO columns | ✅ | ADR-031 |
| Knowledge tests from the Sprint 5 task engine scored automatically | ⏳ | Not linked: the assessor enters the score (see §7) |

### Web
| Item | Status |
|---|---|
| Provider: instruments and policy; results grid; moderation summary and reports; readiness and statements | ✅ |
| Assessor: cohorts, learner results, decision dialog with evidence | ✅ |
| Moderator: queue, sample and appeal decisions, report signing | ✅ |
| Learner: My results, appeal, Statement of Results download; public check page | ✅ |

### Mobile
| Item | Status |
|---|---|
| Assessor: cohorts, learners, instruments; observation and test decisions offline with photos; waiting and refused decisions | ✅ |
| Learner: results, feedback, appeal, Statement of Results share | ✅ |
| Push notices for results and appeals open My results | ✅ |

## 3. Performance (spec §5)

| Operation | Measured | Budget |
|---|---|---|
| Web initial JavaScript | 122.4 KB gzip | 160 KB |

Recording a decision and the results grid (target: 300 learners × 20 modules under 300 ms) were not measured with seeded volumes this sprint. Both are carried into Sprint 8 (§8).

## 4. Security and POPIA

- **Registrations are checked when the decision is made**, not only when staff are added:
  - an assessor must be registered for the qualification on the date assessed;
  - a moderator needs a valid moderator registration and also a valid assessor registration;
  - a moderator can never moderate their own decision.
- **Nothing is edited:**
  - Results, overturns and appeal outcomes are separate, linked records, each fingerprinted.
  - Statements of Results are withdrawn and re-issued, never changed.
  - The public check shows "withdrawn" or "record changed".
- **The public check page** shows the learner's name and a masked ID number, the qualification and the status. It shows no module marks or feedback.
- **Learners don't see** who assessed them or the integrity checks. Evidence files are visible only to staff with `learners.view`.
- **Every decision, moderation, appeal, issue and withdrawal is audited.**

## 5. Accessibility

axe (WCAG 2.2 AA): **108 screens, 0 violations**, in CI. That includes the assessor's decision dialog, the moderator's queue and dialogs, the Statement of Results pages, and the learner's results on a phone.

## 6. Quality

| | Sprint 6 | Sprint 7 |
|---|---|---|
| API tests (Pest) | 356 | **367** |
| Mobile tests | 31 | 31 (plus the results push route) |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| ADRs | 28 | 31 (ADR-029 to 031) |

## 7. Changes from the spec (for your approval)

1. **Sampling is decided as each result is recorded** (risk-based, then a repeatable share), rather than by a sampling plan drawn up later. Results outside the sample are final at once, so learners aren't held up.
2. **Decisions by the cohort's own facilitator are always sampled** (not only in the first cohort). That is simpler to explain to a verifier.
3. **Knowledge tests aren't linked to the Sprint 5 task engine yet.** The assessor records the score.
4. **The moderation report is per cohort and module** and is signed once every sampled decision is moderated.
5. **The Statement of Results layout is ours.** The QCTO publishes its own SoR template, so we may need to match its layout once TYM shares the current version (*external*). The snapshot holds all the data either way.
6. **API path `/cohorts/{id}/statement-readiness`**, because `/cohorts/{id}/readiness` is already the cohort-opening checklist from Sprint 3.

## 8. Carried into Sprint 8

- Columns AM (assessment centre), AO and AP (foundational learning) of the QCTO file, and EISA registration.
- Linking task-engine tests to summative instruments.
- Measuring decision recording and the results grid at 300 learners × 20 modules.
- The mobile profile fields for the QCTO file (from Sprint 4). Visual check on a phone (*external*: EAS build). ffmpeg on production workers.

## 9. Needed from you / TYM

- Approve this report and the Sprint 8 spec (`sprint-08-zero-rejection-eisa.md`).
- **TYM:**
  - The Bricklayer curriculum and assessment specification (QAS).
  - The provider's assessment and moderation policy.
  - The current QCTO Statement of Results template.
  - The EISA dates for CETA qualifications and the assessment centre(s) TYM uses.
- **Accounts:** EAS, Apple and Google; the Home Affairs reseller; LAWtrust sandbox; Meta templates; gateway keys.
