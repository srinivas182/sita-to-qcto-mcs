# ADR-031: Statement of Results

**Status:** Accepted · Sprint 7c

## Context

The provider issues the Statement of Results once a learner completes all modules of all components; it makes the learner eligible for the EISA and goes to the QCTO before the EISA. The QCTO file has its columns: AK Statement of Results status (01 issued, 02 not yet), AL issue date (YYYYMMDD, blank while not issued) and AN readiness type. Paper statements are easy to forge and hard to correct cleanly.

## Decision

1. **Readiness per learner** lists what stands in the way, in plain words:
   - every module not an untaken elective is Competent in every active instrument;
   - every latest result is **final** (moderated if it was sampled);
   - no appeal is open;
   - every work experience module's logbook sign-off is **checked** (ADR-028).
2. **Issuing** (`statements_of_results`, `cohorts.manage`, with a confirmation tick) takes a **snapshot**: provider and centre, learner (masked ID number), qualification, each module with its outcome and date (work experience with signed hours), and the Statement of Work Experience (employers, workplaces with approval numbers, mentors, periods).
   - **Number** per provider and year: `TYM-SOR-2027-00001`.
   - **Fingerprint** over the number, enrolment, issue time and the snapshot in canonical form (keys sorted, because the database's `jsonb` does not keep key order).
   - **Public check**: a 10-character code; `GET /v1/public/sor/{code}` and the page `/sor/{code}` show provider, learner, qualification, number, date, status and whether the record is intact.
   - One issued statement per enrolment at a time.
3. **Corrections:** withdraw with a reason (the check page then shows it as withdrawn), then issue a new one with a new number. Nothing is edited.
4. **PDF** on request (not stored), with the check link and fingerprint. Staff with `cohorts.view` and the learner can download it.
5. **QCTO file:** AK = 01 and AL = the issue date once issued, and AN = 4 (SDP training and assessment for readiness to EISA); otherwise AK = 02, AL blank, AN = 1.
6. The learner is told by push and sees the statement on My results.

## Consequences

- EISA registration, the assessment centre code (AM) and the foundational learning columns (AO, AP) come in Sprint 8 with the zero-rejection checks.
- The QCTO's own SoR template may ask for layout changes; the snapshot keeps the data either way.
