# Sprint 6: Workplace logbook working on site

**Plan dates:** 1 – 12 Feb 2027 (Phase 1 – SaaS compliance core).
**Status:** draft for approval (decisions in §9).

**Goal (from the sprint plan):** *Workplace logbook working on site.*

**Demo at the end:** *A learner logs a day on a building site with no signal, and the mentor signs it off on their phone.*
1. The provider sets the logbook rules for each work experience module (WM): the work activities, hours needed and evidence asked for, taken from the curriculum.
2. On site with no signal, the learner logs the day in the app: the activity, hours, a photo, a short note (typed or spoken). The phone stamps the time and distance from the approved site, and chains each entry to the one before.
3. Back in signal, entries are sent. The mentor gets a notice and signs off the week in the app, or sends an entry back with a comment.
4. The provider sees hours per module against what is needed, unsigned weeks, and learners who have stopped logging.
5. The monthly register gains the workplace days, and the mentor signs those too.
6. At the end of a module the mentor gives the final sign-off and confirms authenticity. The provider's assessor checks the logbook for the Statement of Work Experience.

---

## 1. Where the market stands (Nov 2026)

**Regulation:**
- The QCTO curriculum template has, for each **work experience module**, a list of work experience competencies and their scope, and a **Statement of Work Experience** section ([QCTO curriculum template](https://www.qcto.org.za/assets/curriculum-document-template-vers-1.2-23102023.docx)). The logbook should follow that structure, module by module.
- SETA workplace logbooks ask the mentor to:
  - accept their responsibilities;
  - confirm authenticity at the end;
  - give a final sign-off that the learner met the minimum requirements of the workplace component.
  The logbook must be fully signed and ready for the SETA's inspection at verification ([INSETA logbook](https://inseta.org.za/wp-content/uploads/2023/12/LEARNERSHIP-WORKPLACE-EXPERIENCE-LOGBOOK.pdf)).
- Monthly stipends depend on registers from the provider **and/or the host employer** (Sprint 5 finding), so workplace days belong in the register.

| Area | Leaders | What they do well (we adopt) | Their weakness here | Our extra |
|---|---|---|---|---|
| Logging time | Aptem activity log: one place to record off-the-job time as it happens, dated to the day ([Aptem](https://support.aptem.co.uk/hc/en-gb/articles/38195828688786-Activity-log-overview-for-learners)); paper logs: date, hours and signature per task ([UEL](https://uel.ac.uk/sites/default/files/off-the-job-training-log-process-for-paper-based-logs.pdf)) | Log as you go; hours against a plan | Built for UK off-the-job rules, not QCTO work experience modules; paper logs are rebuilt from memory at month end | Entries against **the module's work activities**, hours against what the curriculum needs, **offline first** |
| Proof | Paper signatures; photos stuck into a file | Photos as supporting evidence | Easy to back-date or fill in later | Each entry carries **time, distance from the approved site (not location), photo, and a hash chained to the previous entry**, so changes after the fact show |
| Sign-off | Aptem: everything awaiting verification in one place, and learners flagged after 28 days without an entry ([Aptem](https://www.aptem.co.uk/apprentice)); employer dashboards and signing compliance documents ([Aptem](https://winchester.ac.uk/study/Apprenticeships/APTEM-Apprenticeship-System/)) | Verification queue; inactivity flag | Mentors on site rarely use a desktop | **Mentor signs the week on the phone** (Sprint 3 mentor link), sends entries back with a comment; final sign-off and authenticity statement in the app; inactivity flagged after **14 days** |
| Capturing notes | Typed text | — | Learners on site write little | **Speak the entry** using the phone's own dictation (works with little data), in their language |
| Reporting | Spreadsheets | — | Hours and gaps found at verification | Hours per module, unsigned weeks and inactive learners on one page; workplace days in the monthly register |

## 2. Scope

### 2.1 Backend (`apps/api`)
- **New module `Logbook`:**
  - **Rules per work experience module:** work activities (from the curriculum), hours needed, evidence asked for, and whether each activity needs a photo.
  - **Entries:** learner, placement (Sprint 3 placement and site), module, activity, date, hours, note, photos, distance from the site, offline flag, phone time, and a hash chain.
  - **Sign-off:** weekly by the mentor (sign, or send back with a comment), final module sign-off and authenticity statement, and the provider assessor's check.
  - **Watch:** unsigned weeks after 7 days (remind the mentor), no entries for 14 days (flag the learner), hours behind plan.
- **Registers:** workplace days join the monthly register, with the mentor's signature.
- **Statement of Work Experience** data per learner and module, for Sprints 7–8.

### 2.2 Web (`apps/web`)
- **Provider:** logbook rules per module, logbook per learner, hours dashboard, assessor check.
- **Mentor (Host employer role):** learners and weeks to sign.

### 2.3 Mobile (`apps/mobile`)
- **Learner:** offline logbook (photo, distance, time, hash), speak an entry, hours per module.
- **Mentor:** my learners, weeks to sign (sign or send back), final sign-off.

### 2.4 3D / simulators
- Simulator 1 on web and Android, VR build: depends on the simulator 1 specification (*external*).

## 3. Data model (new)

| Table | Key fields |
|---|---|
| `logbook_rules` | module, activities (json), hours_needed, evidence rules |
| `logbook_entries` | learner, placement, module, activity, date, minutes, note, file_ids, distance_m, offline, device_time, prev_hash, hash, status |
| `logbook_weeks` | learner, placement, week, status, signed_by (mentor), signed_at, comment |
| `logbook_signoffs` | learner, module, mentor final sign-off, authenticity, assessor check |

All with `tenant_id` and forced RLS.

## 4. API (v1, additions)

- `GET/PUT /v1/modules/{id}/logbook-rules`
- `GET/POST /v1/me/logbook` · `POST /v1/me/logbook/{id}` (correct before sign-off)
- `GET /v1/mentoring/weeks` · `POST /v1/logbook-weeks/{id}/sign|return`
- `POST /v1/logbook-signoffs` (final, by the mentor) · `POST /v1/logbook-signoffs/{id}/check` (assessor)
- `GET /v1/cohorts/{id}/logbook` (hours, gaps)

## 5. Performance, security and compliance

- **Offline:** entries and photos are queued on the phone and sent in order. The hash chain is made on the phone and checked on the server.
- **Location:** as in Sprint 5, only the distance from the approved site is kept.
- **Signing:**
  - The mentor signs with their own account (Host employer role).
  - A mentor sees only the learners placed with them.
  - A signed week cannot change. A correction is a new, linked entry.
- **POPIA:** photos on site may show other people. The app reminds learners to photograph the work, not people.

## 6. Out of scope

- Summative assessment and moderation (Sprint 7).
- The Statement of Results and EISA registration (Sprint 8).
- Payroll or stipend payments.

## 7. Definition of done

- The demo script runs end to end on the web and a phone build.
- Tests for:
  - the hash chain;
  - offline order;
  - mentor scoping;
  - sign and return;
  - final sign-off;
  - register workplace days;
  - reminders and the inactivity flag.
- axe clean; CI green; merged; close report; sprint sheet updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Mentors don't sign | Weekly reminders, one tap per week; the provider sees unsigned weeks |
| No signal on site for days | Offline queue with a long window; the phone's clock is checked against the server when synced |
| Curricula not loaded (Bricklayer WM modules) | Rules can be entered by hand until the curriculum is loaded (*external*: TYM) |
| Dictation quality in African languages | The phone's own dictation first; server transcription later only if needed |

## 9. Decisions (for approval)

1. **Who signs:** recommend **weekly sign-off by the workplace mentor in the app**, a **final module sign-off with an authenticity statement** by the mentor, and a **check by the provider's assessor**.
2. **Proof per entry:** recommend **time, distance from the approved site, an optional photo and a hash chain**, with no stored location and no biometrics.
3. **Hours:** recommend **hours needed per work experience module set by the provider**, defaulting to the curriculum's notional hours (10 per credit).
4. **Inactivity:** recommend flagging learners **after 14 days without an entry** (Aptem uses 28), and reminding mentors **after 7 days** with an unsigned week.
5. **Voice entries:** recommend the **phone's own dictation** this sprint (free, works with little data). Server transcription only later, if it's needed.
