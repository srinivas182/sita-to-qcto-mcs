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

## Final sign-off, assessor check, registers and reminders (Sprint 6c)

15. **Final sign-off** (`logbook_signoffs`, one per enrolment and WM module): the placement's mentor signs off once every entry for the module is signed and the signed hours reach the hours needed. They tick the authenticity statement (versioned, `v1`): *"I confirm that the learner did the work in this logbook at our workplace, under my supervision, and that the entries are true."* The sign-off records the hours and a signature over the module's signed entry hashes.
16. **Assessor check**: a provider assessor (`assessment.conduct`, scoped to the cohort's centre) confirms the sign-off, or queries it with a comment. Confirming is refused if the logbook changed after the sign-off. After a query, the mentor can sign off again.
17. **Statement of Work Experience data** (`GET /enrolments/{id}/work-experience`): workplaces with approval numbers and mentors, hours per module against what is needed, sign-offs and the chain check. A module is complete once its sign-off is checked. Sprint 7 uses it for the Statement of Results.
18. **Monthly register**: Delivery asks for workplace days through a `WorkplaceDays` contract, which the Logbook module provides (so Delivery does not depend on Logbook). Each learner's line shows the days and hours logged at the workplace. The placement's mentor signs the register as `mentor`. A month with only workplace days can have a register. A mentor sees only their learners' workplace days, not their classes.
19. **Reminders** (`logbook:watch`, daily at 17:40): mentors get one message listing weeks still unsigned 7 days after the week ended, then weekly. Learners on a placement who have logged nothing for 14 days get a nudge, then every 14 days. Push first; SMS or WhatsApp when the person has no app.

## The app (Sprint 6d)

20. **Offline first.** The learner writes an entry on the phone with no signal. The phone keeps the photos in the app's own folder, fingerprints them (SHA-256 of the bytes, the same as the server's file hash), takes the location once (only the distance is kept on the server), stamps the local time with its offset, and chains the entry onto the newest entry still waiting, or the server's last hash. `src/core/entryHash.ts` matches the PHP `EntryHash` (shared test vector).
21. **A logbook queue**, separate from the general outbox because photos are uploaded before the entry: entries are sent oldest first; a network failure stops the run (order kept, uploaded photo ids kept for the retry); a refusal is kept with the provider's reason for the learner to read and remove.
22. **Voice**: the phone keyboard's own dictation (no server transcription).
23. **The mentor in the app**: weeks to sign (sign with the confirmation, or send back chosen entries with a comment), learners' hours per module, and the module sign-off with the authenticity statement. Mentors sign the monthly register's workplace days in the app too.
24. Push notices open the logbook (learner) or the mentor screen (`logbook_mentor`).

## Consequences

- Weekly mentor sign-off (6b) and final sign-off with the authenticity statement (6c) build on these entries.
- The chain proves entries were not changed after writing; it cannot prove the learner told the truth. Distance, photos and the mentor's weekly signature cover that.
- No biometrics and no stored location, in line with POPIA minimality.
