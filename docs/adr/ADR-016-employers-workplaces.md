# ADR-016: Host employers, workplace approvals, mentors and capacity

- Status: Accepted
- Date: 2026-11-22
- Sprint: 3c

## Context

Occupational qualifications include work experience modules completed at a host employer.
Host employers are not accredited; a SETA approves each **workplace** for named
qualifications, for a period. Each learner needs a workplace mentor, and SETAs cap how many
learners one mentor may guide (commonly 1:10, sometimes stricter for a qualification).
Leaders' work-based learning tools (aXcelerate, for example) record placements, supervisors
and logbooks. None of them track SETA workplace approval or mentor-based capacity, so
providers find out about an expired approval or an over-full site only at verification.

The standard `host-employer` role is provider-wide. Without more, it can't say *which*
employer a person acts for.

## Decision

1. **Four provider-owned tables**, all protected by row-level security:
   - `employers`: CIPC number (format checked), SDL number (`L` + 9 digits), SETA from the
     21 SETAs, B-BBEE level, contacts.
   - `employer_sites`: address, GPS (bounded to South Africa), approval number, approving SETA,
     dates, approved qualifications (catalogue entries), the letter, learners per mentor
     (default 10) and per-qualification overrides.
   - `employer_mentors`: name, title, years of experience, highest qualification and NQF
     level, sites. A mentor may optionally be linked to a person who signs in.
   - `employer_members`: people (with the `host-employer` role) who act for the employer.
     3e uses this to scope what they see.
2. **Approval state is computed, never stored**: approved, expiring (≤ 90 days), expired, not
   approved. The list shows each employer's worst state across its sites.
3. **Capacity = active mentors at the site × learners per mentor**, per approved
   qualification. It is zero while the approval is out of date or doesn't cover the
   qualification. In 3e, cohorts subtract placed learners and refuse placements beyond it.
4. **Mentor requirements stay in the curriculum.** We show the curriculum's own work
   experience criteria (from the catalogue's WM modules) beside the mentor list, rather than
   encode invented rules. Curricula word these differently, and getting them wrong would
   block valid mentors.
5. **Daily expiry alerts** (`employers:approval-alerts`, 06:40) at 90, 60 and 30 days and on expiry, sent once per threshold. They reset when the date is renewed. The queued mail
   carries plain values only, as with the other alerts.
6. The approval letter is a `workplace_approval` file, which only `employers.manage` may
   upload or open.

## Consequences

- A provider sees, before placing anyone, which workplaces can take learners and how many.
  This is the check leaders leave to the provider.
- Ratios are set by the provider from the SETA's letter. We don't hold a central table of
  SETA ratios, because they aren't published consistently. The catalogue can add a
  per-qualification default later if the JV compliance desk gathers them.
- Mentor self-service (signing in, logbook sign-off) arrives with placements and the mobile
  mentor view (3e, then Sprint 4 logbooks).
