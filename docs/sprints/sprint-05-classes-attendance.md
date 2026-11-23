# Sprint 5: Classes delivered and recorded

**Plan dates:** 18 – 29 Jan 2027 (Phase 1 – SaaS compliance core).
**Status:** draft for approval (decisions in §9).

**Goal (from the sprint plan):** *Classes delivered and recorded.*

**Demo at the end:** *A class runs on a timetable, learners check in on their phones, and the month's register is ready for the SETA.*
1. The provider builds the cohort's timetable: knowledge and practical sessions, venue, facilitator, linked to curriculum modules. Learners and facilitators see it in the app; a change notifies them.
2. At the start of a session the facilitator shows a QR code that changes every 30 seconds. Learners scan it in the app. The check-in is accepted only within the centre's radius, and works offline (sent later, signed by the phone).
3. A learner without a phone is marked by the facilitator, with a reason. Late arrivals and early departures are recorded.
4. The learner opens "My course": the materials for **their** course only, downloaded for offline use, on a low-data video setting.
5. The learner completes a formative task (a quiz and a photo upload). The facilitator marks it with feedback.
6. The provider sees attendance per learner and per module. A learner below 80% is flagged, and the learner and the provider are told.
7. At month end the provider produces the attendance register for the SETA (the stipend evidence), signed in the app by the learner and the facilitator.

---

## 1. Where the market stands (Nov 2026)

**Regulation and funding:**
- **SETA stipends depend on registers.** The Services SETA told Parliament it pays a learner's stipend only for months whose attendance register confirms attendance. Learners went unpaid for months when the provider or employer did not send the register ([pa.org.za](https://pa.org.za/speech/168866362), [pa.org.za](https://pa.org.za/speech/167668976)). A missing or late register costs the learner money.
- **SETA close-out packs:** INSETA's learnership checklist asks for progress and closure reports signed by employer, learner and provider, and proof of stipend payment ([INSETA 2024](https://inseta.org.za/wp-content/uploads/2024/06/Learning-Division-Compliance-Workshop-Presentation.pdf)).
- **QCTO:** the provider must assess all outcomes, give feedback and record the evidence, and keep learner records properly. The QCTO monitors accredited providers with enrolled learners ([QCTO quick guide](https://QCTO.ORG.ZA/assets/quick-guide---implementation-of-occupational-qualifications.pdf)). Formative results feed the Statement of Results later.

| Area | Leaders | What they do well (we adopt) | Their weakness here | Our extra |
|---|---|---|---|---|
| Timetables | aXcelerate workshops and sessions; Moodle calendars | Sessions with trainer and venue; learner calendar | Not linked to the QCTO curriculum (knowledge, practical, workplace); no clash checks with staff registrations | Sessions linked to **curriculum modules**; facilitator must hold a valid registration for the module (Sprint 3); room and facilitator clash checks; changes pushed to the app |
| Attendance | aXcelerate app: trainers mark attendance, also offline, and capture learner signatures ([aXcelerate](https://support.axcelerate.com.au/hc/en-gb/articles/13544832040847)). Bridge and Acorn: a fixed QR per session for self check-in ([Bridge](https://community.bridgeapp.com/hc/en-us/articles/46330529555731-How-to-Register-Learner-Attendance-for-Live-Training-Sessions-With-a-QR-Code), [Acorn](https://help.acorn.works/help-centre/qr-self-check-in)). SoWeSoft (SA): passcodes, QR, links and phone-call checks ([SoWeSoft](https://sourceforge.net/app/sowesoft-trainer/android/)) | Fast trainer marking; learner self check-in; signatures | aXcelerate users still ask for QR self check-in ([community request](https://support.axcelerate.com.au/hc/en-gb/community/posts/16027579700367-QR-Code-Attendance)). A fixed QR or link can be forwarded to someone at home. Biometric devices have been questioned when stipends went unpaid ([OUTA](https://outa.co.za/web/content/234058)) | **Rotating QR (30 s) + centre geofence + device key**: can't be forwarded or done from home. **Works offline**, signed on the phone and synced. Facilitator override with a reason, all audited |
| Registers for SETAs | Paper registers, scanned; spreadsheets | — | Lost or late registers mean unpaid stipends | **Monthly register PDF** per cohort, from the check-ins, signed in the app by learner and facilitator, with a deadline reminder. Workplace days join in Sprint 6 (logbook) |
| Course materials | Moodle, TalentLMS, aXcelerate learner app | Courses, files, video, progress | The whole catalogue is often visible; video eats data; offline is limited | **Only the enrolled course** (Sprint 4 rule at the API). **Offline packs** with a low-data video setting. Materials tagged to curriculum modules |
| Formative tasks | Moodle quizzes and assignments; aXcelerate assessments | Question banks, uploads, marking with feedback | Not linked to curriculum modules or to the QCTO evidence trail | Tasks per module, **photo or video evidence from the phone**, marking with feedback, and results kept for the Statement of Results (Sprint 7–8) |
| At-risk learners | Manual reports | — | Found too late | **Attendance threshold alert** (default 80%) to learner, facilitator and provider |

## 2. Scope

### 2.1 Backend (`apps/api`)
- **New module `Delivery`:**
  - **Sessions:** cohort, curriculum module, kind (knowledge, practical), start and end, venue (centre room), facilitator. Recurring patterns. Clash checks (facilitator, room). Facilitator registration checked against the module (Sprint 3).
  - **Attendance:** check-in and check-out per learner per session. Method (QR, facilitator), GPS distance, device, time, offline flag, late and early minutes. Facilitator overrides with a reason. Absence reasons (sick note upload).
  - **Rotating QR:** a signed token per session that changes every 30 seconds, valid with a short grace period.
  - **Registers:** monthly register per cohort, PDF, signatures in the app (learner, facilitator), submitted date.
  - **Threshold watch:** daily, attendance below the provider's threshold is flagged and notified.
- **Content:** materials (file, video, link) per qualification and module, published versions, only for enrolled learners (`mayLearn`). Video gets a low-data variant. Offline packs list what the app should download.
- **Formative tasks:** quiz (multiple choice, short answer) and evidence upload; attempts; marking with feedback; results per module.
- **3D:** simulator 1 scoring and a results API (attempt, score, time, steps), once the simulator 1 specification is signed off.

### 2.2 Web (`apps/web`)
- **Provider:** timetable builder per cohort (week view, clash warnings), session page with the live QR and the check-in list, attendance report per learner and module, monthly registers, content library, task builder and marking queue.
- **Facilitator:** today's sessions, show QR, mark attendance, mark tasks.

### 2.3 Mobile (`apps/mobile`)
- **Learner dashboard:** today's sessions, attendance so far, tasks due, course progress.
- **Check-in:** scan the session QR; GPS checked; works offline.
- **My course:** materials by module; download for offline; low-data video.
- **Tasks:** answer quizzes, upload photo or video evidence, see feedback.
- **Registers:** sign the month's register.

## 3. Data model (new)

| Table | Key fields |
|---|---|
| `sessions` | cohort, module, kind, starts_at, ends_at, room, facilitator, status |
| `attendances` | session, learner, status (present, late, absent, excused), in/out times, method, distance_m, device, offline, overridden_by, reason |
| `attendance_registers` | cohort, month, file, signatures, submitted_on |
| `materials`, `material_versions` | qualification, module, kind, file or link, published |
| `tasks`, `task_attempts` | module, kind, questions; learner, answers, evidence files, score, feedback, marked_by |

Every table gets `tenant_id` and forced RLS. Materials can also be shared from the catalogue (platform-owned, read-only to providers).

## 4. API (v1, additions)

- `GET/POST/PATCH /v1/cohorts/{id}/sessions` · `GET /v1/sessions/{id}/qr` (facilitator) · `POST /v1/me/check-ins`
- `GET /v1/cohorts/{id}/attendance` · `POST /v1/sessions/{id}/attendance` (facilitator marking)
- `GET/POST /v1/cohorts/{id}/registers` · `POST /v1/me/registers/{id}/sign`
- `GET/POST /v1/materials` · `GET /v1/me/courses/{id}/materials` · `GET /v1/me/courses/{id}/offline-pack`
- `GET/POST /v1/tasks` · `POST /v1/me/tasks/{id}/attempts` · `POST /v1/task-attempts/{id}/mark`
- `GET /v1/me/dashboard`

## 5. Performance, security and compliance

- **Performance:** check-in p95 under 100 ms (500 learners checking in within 5 minutes at one centre). Timetable for a cohort under 150 ms. Offline packs are delta-based, so a re-download fetches only changes.
- **Security:**
  - QR tokens are HMAC-signed, expire in 30 seconds (plus grace for offline sync with the phone's signed time).
  - Check-ins from outside the radius are refused online and flagged when synced offline. Mock-location detection on Android.
  - Content is served with short-lived signed URLs, only to enrolled learners.
- **POPIA:** location is kept only as the distance from the centre and a pass or fail, never a raw track. Sick notes count as health information: visible only to staff with the attendance permission, removed with the enrolment's retention.

## 6. Out of scope

- Workplace logbook and supervisor sign-off (Sprint 6). The monthly register gains workplace days then.
- Summative assessment, moderation, Statement of Results (Sprints 7–8).
- SCORM and H5P packages (Phase 2, see §9).
- Live online classes (video calls).

## 7. Definition of done

- The demo script runs end to end on the web and a phone build.
- Tests for every rule: QR rotation and expiry, geofence, offline sync, overrides, clash checks, the access rule on materials, threshold alerts, register totals. Isolation tests for new tables. axe clean.
- CI green, merged, close report, sprint sheet updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Learners without smartphones | Facilitator marks them; the register shows the method |
| Weak GPS indoors | Generous default radius (200 m); facilitator confirm when GPS is unavailable |
| Data cost of video | Low-data variant (360p), Wi-Fi-only download option, offline packs |
| Simulator 1 specification late | Scoring API built against the agreed shape; the simulator joins when ready |

## 9. Decisions (for approval)

1. **Proof of attendance:** recommend **rotating QR + centre geofence (200 m default) + device key**, with a facilitator override and reason. No biometrics.
2. **At-risk threshold:** recommend **80%** per month, editable per provider.
3. **Monthly SETA register:** recommend building it now from check-ins, signed in the app by learner and facilitator. The employer signs workplace days when the logbook arrives (Sprint 6).
4. **Content formats this sprint:** recommend PDF, images, MP4 video (with a 360p low-data copy) and links. **SCORM and H5P in Phase 2**: they add a player and tracking runtime, and few SA providers have packages yet.
5. **Video storage:** recommend our own file storage (per provider, Sprint 2), not YouTube. It keeps access to enrolled learners and allows offline download.
