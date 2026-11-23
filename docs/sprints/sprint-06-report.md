# Sprint 6: Workplace logbook working on site (close report)

**Status:** Build complete. Parts 6a to 6d merged with CI green. Items that wait on accounts, documents or people outside the code are marked *external*.

**Pull requests:**
- #30 logbook rules, placements and chained entries
- #31 weekly mentor sign-off
- #32 final module sign-off, assessor check, register workplace days, reminders
- #33 the app (learner offline logbook, mentor signing) and close (this report)

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. Provider sets the logbook rules per work experience module | ✅ | Programmes → Logbook: activities (with "needs a photo"), hours needed (credits × 10 by default), a note for learners |
| 2. On site with no signal, the learner logs the day: activity, hours, photo, note (typed or spoken); time, distance and a chained fingerprint | ✅ | App → Learn → Workplace logbook. Saved on the phone first; the fingerprint matches the server's (shared test vector). Speaking uses the keyboard's dictation |
| 3. Back in signal, entries are sent; the mentor signs the week or sends entries back | ✅ | Sent oldest first, photos before the entry; no duplicates on retry. Mentor: app or web "Logbooks to sign" |
| 4. Provider sees hours per module, unsigned weeks, learners who stopped logging | ✅ | Cohort → Logbook: hours against what is needed, unsigned weeks, last entry, "Intact" / "Changed after signing" |
| 5. Monthly register gains workplace days; the mentor signs them | ✅ | Days and hours per learner on the register and its PDF; mentor signs as "Workplace mentor" |
| 6. Final module sign-off with authenticity; assessor checks it for the Statement of Work Experience | ✅ | Versioned statement; assessor confirms or queries; Statement of Work Experience data per enrolment |

Demo data: Bongani is placed at the Soweto yard with Bheki (bheki@demo.test) and has a week of entries waiting to be signed.

## 2. Scope checklist (spec §2)

### Backend
| Item | Status | Where |
|---|---|---|
| Rules per WM module; placements; entries with hash chain, corrections, idempotent sends | ✅ | `modules/Logbook`, ADR-028 §1–8 |
| Weekly sign-off and return; mentor scoping | ✅ | ADR-028 §9–14 |
| Final sign-off, authenticity, assessor check | ✅ | ADR-028 §15–16 |
| Watch: unsigned weeks (7 days), no entries (14 days) | ✅ | `logbook:watch`, daily 17:40 |
| Hours behind plan | ✅ (shown) | Hours against what is needed on the cohort logbook and in the app. No separate alert (see §7) |
| Workplace days in the monthly register, signed by the mentor | ✅ | `WorkplaceDays` contract; ADR-028 §18 |
| Statement of Work Experience data | ✅ | `GET /v1/enrolments/{id}/work-experience` |

### Web
| Item | Status |
|---|---|
| Provider: logbook rules per module; placements; hours, unsigned weeks and chain check; a learner's entries with photos; assessor check | ✅ |
| Mentor: Logbooks to sign (weeks, send back, module sign-off) on a phone-sized screen | ✅ |
| Registers show mentors; the mentor's register page shows workplace days | ✅ |

### Mobile
| Item | Status |
|---|---|
| Learner: offline logbook (photo, distance, time, fingerprint), dictation, hours per module, fixing entries sent back, refused entries shown with the reason | ✅ |
| Mentor: weeks to sign (sign or send back), learners' hours, final module sign-off | ✅ |
| Registers: mentor signs the workplace days | ✅ |
| Push notices open the logbook or the mentor screen | ✅ |

### 3D / simulators
| Item | Status |
|---|---|
| Simulator 1 | ⏳ *external*: waits for the simulator 1 specification |

## 3. Performance (spec §5)

| Operation | Measured | Budget |
|---|---|---|
| Recording an entry (hash check, rules, placement, day total, week) | p50 14 ms, p95 21 ms | 100 ms |
| Chain check of a logbook with 331 entries | 18 ms | — |
| Web initial JavaScript | 121.9 KB gzip | 160 KB |

## 4. Security and POPIA

- **Tamper evidence:**
  - Every entry is chained by SHA-256 to the one before. The server refuses an entry whose fingerprint doesn't match.
  - A week's signature and a module sign-off each cover the entries' fingerprints. A later change shows as "Changed at entry n" or "Changed after signing", and an assessor can't confirm it.
- **Nothing is overwritten.** A correction is a new entry that replaces the old one, and both stay in the chain. Signed weeks are closed.
- **Location:** no coordinates are stored, only the distance from the approved site and whether it counts as on site.
- **Photos:** the app reminds learners to photograph the work, not people. A photo's fingerprint is part of the entry, so it can't be swapped later. Photos are visible to the mentor and provider staff with `learners.view`.
- **Scoping:** a mentor sees only the learners placed with them, not other mentors' learners at the same employer, and on registers only their learners' workplace days.
- **Every sign-off, return, query and correction is audited.**

## 5. Accessibility

axe (WCAG 2.2 AA): **96 screens, 0 violations**, in CI. That includes the mentor's "Logbooks to sign" on a phone-sized screen and its dialogs.

## 6. Quality

| | Sprint 5 | Sprint 6 |
|---|---|---|
| API tests (Pest) | 342 | **356** |
| Mobile tests | 25 | **31** (fingerprint vector, queue order and retries, push routes) |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| ADRs | 27 | 28 (ADR-028, four parts) |

## 7. Changes from the spec (for your approval)

1. **Correction path:** `POST /v1/me/logbook/{id}/correct` instead of `POST /v1/me/logbook/{id}`, so a correction is clearly a new entry.
2. **Returned entries don't count towards hours** until they are fixed. The day limit is checked without them.
3. **The logbook has its own queue on the phone** rather than the general outbox, because photos must be uploaded before the entry is sent. It keeps the same rules: order, no duplicates, refusals kept for the learner.
4. **"Hours behind plan" is shown, not alerted.** The 14-day inactivity nudge covers learners who stop. A pace alert needs the placement's planned end and a curve; it can come with a later dashboard.
5. **Mentors sign with a tick in the app or on the web**, not a typed name, except on the monthly register, which keeps the typed name like the other signers.
6. **The demo uses a placeholder work experience module** for Bricklayer, marked "(demo)", until TYM sends the curriculum document.

## 8. Carried into Sprint 7

- The mobile profile fields for the QCTO file (carried from Sprint 4).
- Visual check of the mobile screens on a phone (*external*: EAS build). New this sprint: no new packages (expo-camera, expo-crypto and expo-file-system were already in).
- ffmpeg on production workers (from Sprint 5).
- The Statement of Results (Sprint 7) uses the Statement of Work Experience data.

## 9. Needed from you / TYM

- Approve this report and the Sprint 7 spec (`sprint-07-assessment-moderation.md`, assessment through to Statement of Results).
- **TYM:**
  - The Bricklayer curriculum document with its work experience modules and assessment specification (QAS).
  - The provider's assessment and moderation policy (sample size, appeal times), so defaults match it.
  - The simulator 1 specification.
- **Accounts:** EAS, Apple and Google; the Home Affairs reseller; LAWtrust sandbox; Meta templates; gateway keys.
