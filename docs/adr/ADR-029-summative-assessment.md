# ADR-029: Internal summative assessment results

**Status:** Accepted · Sprint 7a

## Context

The QCTO makes internal summative assessment the provider's responsibility: results are recorded per module for the Statement of Results, in any format, under the provider's own quality assurance. SETA verifiers check that a registered assessor made each decision, that learners had remediation, and that records were not changed. Paper forms and spreadsheets make all three hard to show.

## Decision

1. **Instruments per module** (`assessment_instruments`): a method that suits the module kind (KM: test, assignment, project; PM: observation, project, assignment; WM: logbook, observation), a title, criteria from the QAS, a pass mark for tests, and instructions for the assessor. An instrument with results is made inactive rather than deleted, and its method can't change.
2. **The decision** (`assessment_results`) is **Competent** or **Not yet competent**, with a tick per criterion (and a comment), a score for tests, evidence files (`assessment_evidence`), feedback (needed when not yet competent) and remediation.
   - The outcome must follow the rule: every criterion met, and for tests the score at or above the pass mark.
   - The assessor must hold an assessor registration for the qualification that is **valid on the date assessed** (Sprint 3 credentials). The credential is recorded on the result.
   - The assessor's centre scope applies; the date can't be in the future or before enrolment.
   - A **workplace (logbook) instrument** can be Competent only when the mentor's module sign-off has been checked (ADR-028).
3. **Attempts:** each new decision is the next attempt. Once Competent, no more attempts. The provider's policy limits attempts (default 3: two reassessments).
4. **No edits.** A result is fingerprinted (`ResultHash`); a quiet change shows as "Changed after the decision". Moderation overturns and appeal outcomes (7b) create new, linked results.
5. **Module state** is derived: Competent when every active instrument's latest result is Competent; Not yet competent when any is; otherwise in progress or not assessed.
6. **Policy** (`assessment_settings`, one per provider): attempts, moderation sample share, new-assessor decisions always moderated, appeal and decision windows.
7. **Idempotent sends:** a `client_id` from the app's queue makes a retry return the same result.
8. **Learners** see outcomes, scores, feedback and remediation, not the assessor's identity or the integrity check.

## The app (Sprint 7d)

9. **Assessors observe on site, offline.** The app keeps the last copy of the assessor's cohorts, results grid and each learner's instruments, so a practical can be observed with no signal. The decision (criteria, comments, score, feedback, remediation, photos) waits on the phone in its own queue and is sent oldest first, photos before the decision, with a `client_id` so a retry can't duplicate it. The server applies every check when it arrives, including the assessor's registration on the date assessed. Refusals are kept with the reason.
10. **Learners** see results, feedback and what to work on, appeal within the window, and download their Statement of Results to share.

## Consequences

- Moderation (sampling, decisions, report), appeals and the Statement of Results build on these results (7b, 7c).
- Knowledge tests from the Sprint 5 task engine are not yet linked: the assessor records the score.
