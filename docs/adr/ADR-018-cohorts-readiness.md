# ADR-018: Cohorts and the readiness check

- Status: Accepted
- Date: 2026-11-23
- Sprint: 3e

## Context

A provider must not start a cohort that verification will later reject. Several things
have to be true together:
- the qualification still takes enrolments, and will still be achievable at the planned end;
- the centre is accredited and has the qualification in scope;
- every facilitator, assessor and moderator holds a registration that covers the
  qualification, and a moderator is also a registered assessor;
- each workplace is approved, has room under its mentor ratio, and the employer has
  signed with the provider.

Leaders (aXcelerate, Wisenet, Skills Builder) record these facts but don't check them
against each other. Nor do they notice when one lapses after a course starts.

## Decision

1. **A `Cohorts` module**, separate from Qualifications: it draws on Qualifications,
   People, Employers and Agreements, and keeping it apart avoids a circular dependency.
   - Tables `cohorts`, `cohort_staff` and `cohort_placements`, protected by row-level
     security.
   - Statuses: planning (`draft`), `open`, `closed`, `cancelled`.
2. **The readiness check** returns items marked pass, warn or fail. Each has a plain reason
   and a link to where it is fixed. Groups:
   - **Qualification:**
     - Enrolment state on the intake date: fails if closed, ended or unpublished; warns
       if closing.
     - The last achievement date: fails if it falls before the planned end.
     - The programme: fails if not adopted; warns if this mode is not offered.
   - **Centre:**
     - Active and accredited.
     - The qualification in its scope on the intake date; warns if either lapses before
       the planned end.
   - **Staff:**
     - At least one of each role, each eligible on the intake date (using
       `CredentialService::eligibility`); warns if a registration lapses before the end.
     - Warns if the only assessor is also the moderator.
   - **Workplace:**
     - Only when the qualification has work experience modules or the mode is
       workplace.
     - Places planned for every learner.
     - Each site approved for the qualification, and within free places: mentors × ratio,
       less places held by other open cohorts with overlapping dates.
     - The chosen mentor still active at the site.
   - **Agreements:** each host employer has a signed agreement (employer MoU or workplace).
     Fails if there is none; warns if one is out for signature.
3. **Opening runs the check under a row lock** and refuses if anything fails. The check is
   kept with the cohort as evidence (`opened_with`).
4. **Open cohorts stay guarded:**
   - Every change is re-checked in the same transaction and rolled back if it would fail.
   - Qualification and centre are fixed once a cohort is open.
   - `cohorts:watch` (daily, 06:50) re-runs the check and emails owners about each new
     failure once; the cohort list shows "Needs attention".
5. **Centre managers** see and plan cohorts only for their own centres (`centresFor`).
6. An employer's capacity view now shows places held by open cohorts.

## Consequences

- No new rules are invented: each item reuses the module that owns the fact (catalogue
  dates, credential eligibility, workplace approval and ratio, agreement status).
- The check is under 300 ms (tested). It runs a handful of queries, independent of how
  many learners are in the cohort.
- Learner enrolment into a cohort (Sprint 4) will add per-learner items. Examples: an
  identity document on file, and a signed workplace agreement before placement.
