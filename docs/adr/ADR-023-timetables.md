# ADR-023: Timetables (class sessions)

**Status:** Accepted · Sprint 5a

## Context

Sprint 5 records classes (attendance, registers, materials, tasks), and everything hangs on a timetable. Leading LMSs keep sessions with a trainer and a venue, but they don't link sessions to the QCTO curriculum's knowledge and practical modules. They also don't check that the facilitator is registered for the qualification on the day.

## Decision

1. **New module `Delivery`** (registered after Enrolment). The table is `class_sessions`, because `sessions` is Laravel's web session table. Centres gain `rooms`.
2. **A session** belongs to a cohort and has:
   - a kind (knowledge or practical) and an optional curriculum module;
   - a time;
   - a room at the cohort's centre, or another venue;
   - a facilitator.
   Work experience modules are refused here: they belong to the logbook (Sprint 6).
3. **Rules checked on every save:**
   - inside the cohort's dates;
   - the facilitator is on the cohort and holds a verified registration for the qualification **on that day** (Sprint 3 `eligibility`);
   - no clash for the cohort, the facilitator or the room.
4. **Weekly patterns** (days of the week until a date, at most 200 at a time) share a `series_id`. A change or cancellation applies to one session or to "this and the following". Cancelled sessions stay, with the reason, for the record.
5. **Times are entered and shown in South African time** (`Africa/Johannesburg`) and stored in UTC. Other countries can get a provider time zone when they come (Phase 5).
6. **Hours per module:** planned hours against notional hours (10 per credit), so gaps show before the cohort runs.
7. **Changes in the next two weeks** are pushed to enrolled learners and the facilitator.
8. **Permissions:** `cohorts.manage` builds the timetable. Anyone signed in sees their own classes at `/v1/me/timetable`: learners for enrolled cohorts, facilitators for what they teach.

## Consequences

Attendance (5b) attaches to sessions, registers (5c) total them, and materials and tasks (5d, 5e) use the same module links.
