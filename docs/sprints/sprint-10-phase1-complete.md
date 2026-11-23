# Sprint 10: Phase 1 feature complete

**Dates:** 29 March to 9 April 2027 (sprint sheet row 19). **Phase 1: SaaS compliance core.**

**Demo script (what TYM sees at the end):**

1. **Accreditation, extending scope:** a provider starts an application to add a qualification (or renew). The wizard fills the QCTO's readiness forms from what the platform already holds, lists what is missing, and produces the application pack. Expiry alerts already run (Sprint 2); the wizard now links each alert to "start renewal".
2. **Learners earn points** for attendance, tasks passed, logbook entries signed and signing registers on time. Streaks and badges show in the app. A cohort leaderboard is opt-in, can show first names or nicknames only, and is never shown for under-18s.
3. **Readiness score:** each learner sees a score for the external assessment (attendance, modules competent, logbook hours, practice tests), with the next step to raise it. The facilitator sees the cohort's scores.
4. **Group dashboard:** an owner with several centres (or a group of providers under one owner) sees learners, attendance, competence, money ready to claim and open problems per centre, side by side.
5. **Usage and billing:**
   - the provider sees usage against their package (learners, centres, storage, messages);
   - the platform warns at 80% and 100%;
   - messages above the bundle are billed on the next invoice;
   - the Super Admin sees usage per provider.

## 1. Where the market stands (Nov 2026)

| | What leaders do | Weakness | Our answer |
|---|---|---|---|
| QCTO accreditation | QCTO process: online application, desktop evaluation, then a site visit for programme delivery readiness ([QCTO criteria](https://QCTO.ORG.ZA/assets/skills-development-provider-and-assessment-centre-criteria-and-guidelines.pdf)). Forms include the learning material matrix, the programme delivery readiness tool and the application checklist ([SME South Africa](https://smesouthafrica.co.za/getting-seta-accreditation-for-smes/)). Consultants and blogs give checklists (skillSYMS guide) | Paper forms, consultants; evidence gathered by hand and out of date by the site visit | **Wizard filled from live data:** staff credentials, materials per module, sites and workplace approvals, assessment instruments. Gaps listed with links; the pack is regenerated on the day of the visit |
| Gamification | Research reviews find gamified LMSs can help engagement and performance, with effects that depend on the elements used ([MDPI 2025 review](https://www.mdpi.com/2078-2489/16/12/1094/htm)). LMS vendors embed XP and leaderboards (Alison LMS+) | Generic points for clicks; leaderboards can discourage weaker learners; no link to compliance outcomes | **Points only for behaviour that matters for the QCTO:** attendance, signed registers, logbook, tasks passed. Opt-in leaderboard, nicknames, none for minors; badges tied to milestones |
| Dashboards | Training-management products show KPIs such as learners, grants and claims, completion rates (e.g. AI-LMS-TMS guide) | Single-site; numbers without next actions | **Per-centre comparison with the To-fix count** and money ready to claim, each linking to the screen |
| Usage billing | SaaS norm: plan limits with usage meters | Hard stops surprise customers | **Warnings at 80% and 100%**; learners never blocked mid-course; overage on the next invoice |

## 2. Scope

### 2.1 Backend (`apps/api`)
- **Accreditation applications:** per centre, a type (new, extension of scope, renewal) and the qualifications. The readiness checklist is computed from:
  - facilitators, assessors and moderators with valid registrations for the qualification;
  - learning materials and tasks per KM/PM module;
  - assessment instruments per module;
  - workplace approvals for WM modules;
  - centre documents (lease, H&S, insurance) as evidence items.

  The pack (PDF and ZIP) and a status (draft, submitted, desktop evaluation, site visit, approved, declined) are recorded by hand, since the QCTO has no API. An approval updates the centre's scope.
- **Gamification:**
  - a points ledger (append-only), filled from events: attendance check-in, register signed, task passed, logbook entry signed, module competent;
  - streaks (consecutive class days attended);
  - badges as rules;
  - leaderboard per cohort, opt-in, with a nickname; under-18s are excluded and shown nothing;
  - the provider can switch the whole feature off.
- **Readiness score** per learner (0–100) from attendance, competence, logbook hours and practice tests. The formula is versioned and shown to the learner.
- **Group dashboard:** per-centre KPIs. Owners of several tenants can see a group view where a platform-defined group links them (consent from each provider).
- **Usage:** meters for active learners, centres, storage and messages sent per month. Thresholds at 80% and 100% notify the owner. Message overage becomes an invoice line in billing (Sprint 2). The Super Admin usage view.

### 2.2 Web
- Accreditation wizard and application tracker; renewal links from expiry alerts.
- Facilitator view of cohort readiness scores; gamification settings.
- Group dashboard; usage page; Super Admin usage per provider.

### 2.3 Mobile
- Points, streak, badges and the opt-in leaderboard; readiness score with the next step.
- Launching simulators from the app stays with the 3D squad (*external*).

## 3. Data model (new)

| Table | Key fields |
|---|---|
| `accreditation_applications` | centre, kind, qualification_ids, status, submitted_on, decided_on, evidence (json), notes |
| `points_ledger` | user, enrolment, event, points, ref, at (append-only) |
| `badges` / `learner_badges` | key, rule, title; user, badge, earned_at |
| `leaderboard_optins` | user, cohort, nickname |
| `usage_counters` | tenant, metric, period, value |
| `tenant_groups` | name, tenant_ids, consented_by |

## 4. Performance, security and compliance

- Dashboards for 10 centres and 3,000 learners in under 1 second (pre-aggregated counters where needed).
- **Children:** no leaderboard or public ranking for learners under 18. Nicknames are moderated with a blocked-words list.
- **Usage** never blocks a learner from learning, signing a register or being assessed.

## 5. Out of scope

- Submitting accreditation applications to the QCTO electronically (no API); we produce the pack.
- 3D simulators (3D squad).
- Cross-provider leaderboards.

## 6. Definition of done

- The demo script runs end to end.
- Tests: readiness checklist rules, points events (idempotent), streaks, badge rules, under-18 exclusion, readiness formula, usage thresholds and the overage invoice line.
- axe clean; CI green; merged; close report; sprint sheet updated.

## 7. Decisions (for approval)

1. **Accreditation:** we generate the readiness pack and track status by hand. No automated submission.
2. **Gamification is on by default and providers can switch it off.** The leaderboard is opt-in per learner, nicknames only, and never for under-18s.
3. **Points only for compliance-relevant behaviour** (attendance, registers, logbook, tasks, competence). No points for logins or clicks.
4. **Usage never blocks learning.** At 100% we warn and bill message overage; learner and centre limits block only new enrolments, with an upgrade prompt.
5. **Group dashboards across separate providers** need each provider's written consent (recorded). Multi-centre views within one provider need none.
