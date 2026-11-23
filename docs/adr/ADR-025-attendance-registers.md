# ADR-025: Monthly attendance registers and at-risk alerts

**Status:** Accepted · Sprint 5c (decisions 2 and 3 of the Sprint 5 spec)

## Context

SETAs pay learner stipends on the monthly attendance register. When a register is missing or late, the learner goes unpaid. Paper registers get lost, are signed in a hurry, or are signed for someone else.

## Decision

1. **A register is a snapshot** of a cohort's month:
   - the classes that took place, with each learner's mark (present, late, left early, absent, excused, or not enrolled then);
   - totals;
   - a SHA-256 of the snapshot, printed on the PDF.
   Classes with no mark count as absent. Making the month again (before it is sent) replaces the snapshot, and everyone signs the new version.
2. **Made automatically** on the 1st to 5th of each month for every cohort that had classes, and on demand by a manager. Never before the month has ended.
3. **Signatures in the app:**
   - Each learner signs their own line with their typed full name, or disputes it with a reason.
   - Each facilitator who taught that month signs the whole register.
   - The register is **complete** when every facilitator has signed. Learners who did not sign, or disputed, are shown as such on the PDF rather than blocking it.
   - The provider records when it was sent; after that nothing changes.
4. **Learners see only their own line.** The PDF (A4 landscape, made on request, not stored) is for staff.
5. **Reminders:** anyone who has not signed is reminded every three days by WhatsApp or SMS and push.
6. **At risk:** daily at 18:10, learners below the threshold (80% by default, excused absences left out, after at least four counted classes) get a message, at most once a week. The provider's owners and the centre manager get one email per cohort.

## Consequences

- A month's register is ready on the 1st, and the learner's own signature is on it.
- Disputes surface before the SETA sees the register.
- Workplace days join the register with the logbook (Sprint 6).
