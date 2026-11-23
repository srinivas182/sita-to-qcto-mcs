# ADR-027: Formative tasks and marking

**Status:** Accepted · Sprint 5e

## Context

The QCTO expects providers to assess every outcome, give feedback and keep the evidence. Formative results feed the Statement of Results that gives access to the external assessment (EISA). Moodle and similar tools offer quizzes and assignments, but they aren't tied to curriculum modules, and evidence from practical work is hard to capture on a phone.

## Decision

1. **A task** belongs to a qualification and, optionally, a knowledge or practical module. It holds:
   - questions: one answer, several answers (all-or-nothing), or a written answer with a marking guide only staff see;
   - optionally, evidence from the learner (photos, video or a PDF, purpose `task_evidence`), marked out of 10;
   - a pass mark (70% by default) and a number of attempts (3 by default).
   Drafts are hidden from learners until published.
2. **Once anyone has answered**, the questions can't change, so every attempt was made against the same task. Staff make a copy to change them.
3. **Marking:**
   - Choice questions are marked when the attempt is handed in. A task with only choice questions gets its result at once.
   - Written answers and evidence wait for a facilitator on the learner's cohort (or a manager at the centre). They give marks per question, marks for the evidence, and written feedback (required).
   - The learner gets a push notice.
   Results are "passed" or "not yet"; a learner may try again until they pass or run out of attempts. Only one attempt can wait for marking at a time.
4. **Access** follows the enrolment rule (ADR-021): only learners enrolled on the qualification see or answer published tasks. Learners never receive the right answers or the marking guide.
5. **Offline:** the app can hand in an attempt later with the time it was finished (`submitted_at`, recorded as offline). Evidence is uploaded when online.
6. **Results** per cohort show the best attempt per learner and task. They feed the Statement of Results (Sprints 7–8).

## Consequences

- Facilitators have one marking queue, oldest first.
- Every attempt keeps its answers, files, marker, marks and feedback: the evidence trail the QCTO and quality partners ask for.
- Out of scope here: simulator scoring (it waits for the simulator 1 specification) and summative assessment and moderation (Sprint 7).
