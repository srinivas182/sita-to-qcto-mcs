# ADR-028: Workplace logbook entries and their hash chain

**Status:** Accepted · Sprint 6a

## Context

Occupational qualifications include work experience (WM) modules done on site. The QCTO expects a logbook signed by the workplace mentor, and the provider must be able to show that the hours and activities are real. Paper logbooks are often filled in at the end of the placement, signed in bulk, and cannot show whether a page was changed. Leaders (e.g. Learning Management Systems with e-portfolios, and SETA-specific logbook apps) record entries, but few work offline on cheap phones or show tampering.

## Decision

1. **Rules per WM module** (`logbook_rules`), set by the provider: a list of activities (key, title, whether a photo is needed), the hours needed (default: credits × 10), and a note for learners. An activity that learners have logged against cannot be removed.
2. **Learner placements** (`learner_placements`): a learner is placed at one of the cohort's workplaces, optionally with a mentor who works at that site, for a date range. Placements for the same enrolment cannot overlap.
3. **Entries** (`logbook_entries`) are written by the learner, usually on the phone and often offline:
   - one module, one activity, one day (not in the future, within a placement), 15 minutes to 12 hours, at most 14 hours per day;
   - an optional note, photos (purpose `logbook_photo`, required when the activity asks for one), and the device time;
   - the distance from the site when the phone has a location (within 500 m, with up to 100 m of GPS slack, counts as on site). The location itself is not stored, only the distance.
4. **Hash chain.** Each entry carries `sha256("v1|prev|client_id|module|activity|date|minutes|note|photo hashes|device time")` (see `EntryHash`; the app computes the same). The server checks the phone's hash, so an entry changed after it was written is refused. If the phone's chain was behind (two phones, or entries queued offline), the server links the entry onto its latest one, recomputes the hash and marks it `rechained`. Sequence numbers are per learner, written under a row lock.
5. **Corrections** never overwrite: a correction is a new entry with `replaces_id`, and the old one becomes `corrected`. Both stay in the chain. Signed entries cannot be corrected by the learner.
6. **Idempotent sends:** the phone's `client_id` makes a retry from the outbox return the same entry.
7. **Chain check:** the provider's report recomputes every learner's chain and shows "Intact" or the first entry that was changed (e.g. by someone editing the database).
8. Photo hashes in the entry must match the stored files' SHA-256, so a photo cannot be swapped later.

## Weekly sign-off (Sprint 6b)

9. **Weeks** (`logbook_weeks`): one per learner, placement and week (Monday start), created with the first entry. Statuses: open, returned, signed.
10. **Only the placement's mentor** signs, with their own account (Host employer role, linked to the mentor record). Other mentors at the same employer see nothing. Signing needs a tick: "I confirm the learner did this work at our workplace, as written." A future week cannot be signed.
11. **The signature** is `sha256("w1|" + the signed entries' hashes in order)`. If an entry changes after signing, the provider's report shows "Changed after signing".
12. **Send back**: the mentor writes what to fix and picks entries (or the whole week). Those entries become `returned` with the comment and do not count towards hours. The learner corrects them (a new entry, as above); once nothing sent back is left, the week is open to sign again.
13. **A signed week is closed**: no new entries for it, and its entries cannot be corrected by the learner.
14. The learner gets a push notice when a week is signed or sent back. The provider sees unsigned past weeks per learner.

## Consequences

- Weekly mentor sign-off (6b) and final sign-off with the authenticity statement (6c) build on these entries.
- The chain proves entries were not changed after writing; it cannot prove the learner told the truth. Distance, photos and the mentor's weekly signature cover that.
- No biometrics and no stored location, in line with POPIA minimality.
