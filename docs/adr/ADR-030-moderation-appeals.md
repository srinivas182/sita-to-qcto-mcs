# ADR-030: Internal moderation and appeals

**Status:** Accepted · Sprint 7b

## Context

SETA verifiers check that the provider's own moderation sampled enough decisions, that a registered moderator (not the assessor) reviewed them, that learners had remediation and could appeal, and that a report was signed. Leading e-portfolios (OneFile, Smart Assessor) offer a sampling policy with a compliance flag and accept / reject decisions with actions. They sample at random or by plan; they don't target the riskiest decisions by default.

## Decision

1. **Sampling as decisions are made.** Each new result is checked against the policy at once:
   - **always** sampled: Not yet competent; the assessor's first *n* decisions (default 5); decisions by one of the cohort's own facilitators;
   - otherwise sampled at the policy's share (default 25%), chosen **deterministically** from the result's id (`sha256("sample|"+id) mod 100 < share`), so the choice can be explained later;
   - results outside the sample are **final** at once; sampled ones wait for the moderator.
2. **Moderator decisions** (`moderation_samples`): agree (the result becomes final) or overturn with a comment (the result is marked `overturned` and a new, final result with the other outcome replaces it, linked by `replaces_id`). Agreeing with a result whose fingerprint no longer matches is refused.
3. **Who moderates:** a moderator with `assessment.moderate` at the cohort's centre, holding a moderator registration for the qualification that is valid on the day, which (Sprint 3 rule) also needs a valid assessor registration. **Never their own assessment.**
4. **Compliance:** per cohort, module and assessor: decisions, sampled, overturned, and whether the sample meets the policy's share.
5. **Moderation report** (`moderation_reports`): signed per cohort and module once every sampled result is moderated, with findings, recommendations, a snapshot of the summary and sample, the moderator's credential and a fingerprint. Downloadable as a PDF for verification.
6. **Appeals** (`assessment_appeals`): a learner appeals a standing result within the policy window (default 14 days), once per result, with a reason. A moderator other than the assessor decides by the due date (default 21 days): the result stands, or it is overturned as in point 2 (closing any open sample for it). Overdue appeals are flagged. The learner is told either way.

## Consequences

- The Statement of Results (7c) counts only final results: a sampled result counts once moderated.
- Moderation by the Quality Partner stays outside; the report and results are what they ask for.
