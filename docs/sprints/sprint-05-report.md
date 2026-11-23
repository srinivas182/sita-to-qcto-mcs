# Sprint 5: Classes delivered and recorded (close report)

**Status:** Build complete. Parts 5a to 5f merged with CI green. Items that wait on accounts, documents or people outside the code are marked *external*.

**Pull requests:**
- #24 timetables
- #25 attendance
- #26 monthly registers and at-risk alerts
- #27 course materials
- #28 formative tasks
- #29 mobile and close (this report)

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. Timetable with knowledge and practical sessions linked to modules, venue, facilitator; changes notify | ✅ | Weekly patterns, one/following changes, clash and registration checks. Demo: four weeks of Bricklayer classes with Lindiwe |
| 2. Rotating QR at the centre, checked by distance, works offline | ✅ | 30-second signed codes. Only the distance is stored. Offline check-ins are sent within 72 hours and checked against the code's window |
| 3. Learner without a phone marked by the facilitator with a reason; late and early recorded | ✅ | Class page, mark dialog, reviews, absence notes |
| 4. My course: only the enrolled course, offline, low-data video | ✅ | Access checked at the API; offline pack by version; 360p copies made by ffmpeg |
| 5. Formative task (quiz and photo), marked with feedback | ✅ | Quizzes marked at once; written answers and evidence go to the facilitator's queue. Demo: Bongani's photo task is waiting for Lindiwe |
| 6. Attendance per learner and module; below 80% flagged and told | ✅ | Report per learner; daily message to the learner (at most weekly) and an email to the provider |
| 7. Monthly register for the SETA, signed in the app | ✅ | Made automatically on the 1st to 5th. Each learner signs their line (or disputes it), each facilitator signs. PDF, recorded as sent |

## 2. Scope checklist (spec §2)

### Backend
| Item | Status | Where |
|---|---|---|
| Sessions, rooms, patterns, clash checks, registration on the day | ✅ | `modules/Delivery`, ADR-023 |
| Attendance: QR, distance, offline, overrides, absence notes | ✅ | ADR-024 |
| Registers, signatures, PDF, reminders; threshold watch | ✅ | ADR-025 |
| Materials, enrolment-only access, offline pack, low-data video | ✅ | ADR-026 |
| Formative tasks, marking, results | ✅ | ADR-027 |
| Learner dashboard in one response | ✅ | `/v1/me/dashboard` |
| Simulator 1 scoring and results API | ⏳ *external* | Waits for the simulator 1 specification |

### Web
| Item | Status |
|---|---|
| Timetable, rooms; my classes; class page with the live code; attendance report and rules | ✅ |
| Monthly registers; signing page for learners and facilitators | ✅ |
| Materials and tasks per programme; marking queue; task results | ✅ |
| Learner: My course (materials and tasks), browser check-in page | ✅ |

### Mobile
| Item | Status |
|---|---|
| Dashboard: today's classes with check-in, attendance, materials and tasks per course, registers to sign | ✅ |
| Check-in scanner (offline through the outbox, location once, simulated-location flag) | ✅ |
| Timetable; course with offline download and video player; tasks with photo evidence; register signing | ✅ |
| Push notices for registers, timetable changes, attendance and tasks open the right screen | ✅ |

## 3. Performance (spec §5)

| Operation | Measured | Budget |
|---|---|---|
| Check-in (service), 300 learners | p50 6 ms, p95 10 ms | 100 ms |
| Class list of 300 learners (HTTP) | 52 ms | — |
| Web initial JavaScript | 121.7 KB gzip | 160 KB |

## 4. Security and POPIA

- **Check-in codes** are HMAC-signed per provider and change every 30 seconds. A forwarded photo of the code expires within a minute.
- **Location:** no coordinates are stored, only the distance from the centre and a verdict. Simulated locations on Android are flagged.
- **Access to materials and tasks** is checked at the API on every request. Learners never receive right answers or marking guides.
- **Registers:**
  - Learners see only their own line; staff get the PDF.
  - The snapshot fingerprint is printed, so later changes would show.
  - Nothing changes once the register is sent.
- **Every marking, review, signature and dispute is audited.**

## 5. Accessibility

axe (WCAG 2.2 AA): **89 screens, 0 violations**, in CI. That includes a learner on a phone-sized screen. One problem was fixed during the sprint: radio buttons on the class dialog were too small to tap.

## 6. Quality

| | Sprint 4 | Sprint 5 |
|---|---|---|
| API tests (Pest) | 323 | **342** |
| Mobile tests | 22 | 25 |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| ADRs | 22 | 27 (ADR-023 to 027) |

## 7. Changes from the spec (for your approval)

1. **"Sessions" are `class_sessions`**, because Laravel already uses the name `sessions`. The API paths are `/v1/class-sessions/...`.
2. **A register is complete once every facilitator has signed.** Learners who didn't sign, or disputed, are shown that way on the PDF rather than blocking it. A stipend register can't wait for one absent learner.
3. **The register PDF is made on request, not stored**, so it always reflects the latest signatures.
4. **New permission `materials.manage`** (owner, centre manager, facilitator). The 17 standard roles are unchanged.
5. **Facilitators use the web for the live code and marking.** The app is learner-first this sprint.
6. **New mobile packages:** expo-location, expo-video and expo-sharing. They need the next EAS build (*external*).

## 8. Carried into Sprint 6

- Workplace days in the monthly register (they come from the logbook).
- The mobile profile fields for the QCTO file (carried from Sprint 4).
- Visual check of the mobile screens on a phone (*external*: EAS build).
- **ffmpeg on production workers**, for low-data video.

## 9. Needed from you / TYM

- Approve this report and the Sprint 6 spec (`sprint-06-workplace-logbook.md`).
- **TYM:**
  - The simulator 1 specification (workshop).
  - The Bricklayer curriculum document, with its work experience modules: the logbook needs them.
  - The QCTO "Qualification Id" format.
- **Accounts:** EAS, Apple and Google; the Home Affairs reseller; LAWtrust sandbox; Meta templates; gateway keys.
