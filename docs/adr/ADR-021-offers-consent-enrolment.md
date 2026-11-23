# ADR-021: Offers, consent and enrolment

**Status:** Accepted · Sprint 4c

## Context

An applicant becomes a learner in three steps that the QCTO and POPIA both care about: the provider makes an offer, the applicant accepts it and gives consent for their information to be used and reported, and both sign the learner agreement. Leading systems either skip consent records, keep them as a tick box with no wording version, or let staff "enrol" a learner by hand with nothing signed. Learners under 18 need a parent or guardian.

## Decision

1. **Offers** (`offers`) are made only when every required document is accepted, a published learner agreement exists (between learner and provider only), and a place is free. Places = min(intake places, cohort capacity) less open and accepted offers and active enrolments. Each offer has a 48-character link (stored hashed), an expiry (7 days by default, 1–30) and the funding source (SETA grant, employer, self, bursary, government, other; no payments in Sprint 4).
2. **Lapsing and the waiting list:** `enrolment:lapse-offers` runs hourly. A lapsed, declined or withdrawn offer offers the place to the first on the waiting list **whose documents are complete**; others keep their position.
3. **Consent** reuses Sprint 1's `consents` table (purpose `data_sharing_seta`) and adds the wording version (`consent_texts`, versioned per provider), who gave it (a parent or guardian) and the channel (link, web, app). Saving changed wording creates a new version; an accept with an old version is refused so learners always agree to the wording they saw.
4. **Learners under 18** (by date of birth today) must give a parent or guardian's name, own phone number and relationship. The guardian is added as an extra signer on the learner agreement (role `guardian`, after the learner), allowed only on learner agreements.
5. **Enrolment happens on signing, not on accepting.** `AgreementService::complete` dispatches `AgreementCompleted`; the Enrolment listener creates the `enrolments` row (unique per cohort and learner), assigns the learner role at the cohort's centre (from then on the provider is billed), marks the application enrolled and tells the learner.
6. **Access rule:** `/me/courses` lists only enrolled courses, and `EnrolmentService::mayLearn()` is the single check Sprint 5's learning content will use.
7. **Readiness** gains a Learners group: more learners than places fails; accepted offers waiting for signatures warn. It reads the tables directly so Cohorts does not depend on Enrolment.

## Consequences

- Nobody is enrolled without consent, a signed agreement and accepted documents; the audit log shows each step.
- Controllers resolve services per request (controllers are reused between requests, the tenant context is not).
- Open: an identity check against Home Affairs (4d) and the QCTO enrolment list with its 21-day countdown (4d).
