# ADR-037: The "To fix" inbox, learners' one-tap fixes, and online tests for summative instruments

- Status: Accepted (Sprint 9d)
- Date: 2026-11-23

## Context

By Sprint 9 the platform raises problems in many places: QCTO rejections (8b), EISA closing dates (8c), registers to send (5), funder queries, tranches ready to claim, stipends to confirm or held (9a–c). A provider had to visit each screen to find them. Learners also had things only they could fix (bank details, the EISA declaration, profile details the QCTO would reject), with nothing prompting them. Separately, Sprint 7 left summative tests unlinked from the Sprint 5 task engine, so a learner's online test score had to be retyped.

## Decision

- **`GET /v1/fix-inbox`** (`FixInbox`) collects the open items the person may see, sorted by severity (`danger`, `warning`, `info`). Each item has a title, a detail and a link (`to` and `params`) to the web screen where it is fixed. Sources:
  - QCTO rejections not resolved;
  - registers signed but not sent;
  - EISA closing in 30 days or less with learners not registered (danger at 14 days or less);
  - open funder queries;
  - tranches with unclaimed learners, ready or waiting on evidence;
  - stipend months to confirm or pay;
  - stipends held for missing bank details.

  Funding totals (ready to claim, claimed and unpaid, paid this year, stipends held) are returned only with `funding.view`. Items are scoped by the person's centres.
- **The provider home page** shows the totals and the list.
- **`GET /v1/me/todo`** (`LearnerTodo`) returns:
  - bank details missing (only when a stipend is set on the learner's agreement);
  - EISA declarations to sign;
  - QCTO profile errors.

  The app's Home shows one card per item, opening the screen where it is fixed.
- **`assessment_instruments.task_id`** links a test or assignment to a published task of the same qualification (for that module, or for no module). The learner results endpoint returns the learner's attempts per linked instrument. Web and app show the latest attempt in the decision dialog and fill in the score, and **the assessor still decides**: the task's own pass mark is shown, but the instrument's pass mark decides.

## Consequences

- One place to start the day. New problem sources add a block to `FixInbox` rather than a new screen.
- The inbox is built on request. It is fast at the current scale (funding progress for 300 learners takes about 50 ms), but a provider with many agreements may need it cached; we'll measure at Phase 1 demo scale.
- Online scores still pass through an assessor's decision, which keeps the QCTO's requirement for a registered assessor on summative results.
