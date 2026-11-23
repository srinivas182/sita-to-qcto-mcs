# ADR-034: Grant agreements, tranches and computed milestones

- Status: Accepted (Sprint 9a)
- Date: 2026-11-23

## Context

SETAs pay discretionary grants in tranches. A provider claims a tranche when its milestones are met: merSETA asks for one claim form per tranche, and BANKSETA pays only with the supporting evidence. Providers count learners by hand against registers, logbooks and results, and claims stall when evidence is missing. Approved with the Sprint 9 spec: milestones are worked out from the platform's records (decision 1).

## Decision

- **New module `Funding`**, all tables tenant-scoped under RLS:
  - `funders`: SETA, employer or other, with a claim template (`generic`, `ceta`);
  - `grant_agreements`: one MoA per cohort, with the learner count, the amount per learner in cents, full or co-funded, an optional disability allowance, the dates and the signed MoA file;
  - `grant_learners`: the enrolments the agreement funds. A learner taken off keeps the row with status `removed`, so past claims still add up;
  - `grant_tranches`: number, share of the per-learner amount, milestones, evidence items and an optional due date.
- **Milestones are a fixed list worked out from records:**
  - `enrolled`: enrolled, and the learner agreement completed;
  - `qcto_submitted`: on a QCTO enrolment submission, with no open rejection on the latest one;
  - `registers` (N months): signed monthly registers;
  - `logbook`: every compulsory work module signed off and checked;
  - `sor`: Statement of Results issued;
  - `eisa`: registered for the EISA.

  Anything else is an **evidence item**: a titled file the provider uploads (a registration letter, a close-out report).
- **`GrantProgress`** loads each fact for all learners of an agreement in a fixed set of queries and returns, per tranche: learners met, the claimable amount (share × per-learner amount × learners met), missing evidence and `ready`. Per learner it gives the blockers in plain words.
- **Checks on save:**
  - tranches add up to no more than 100%;
  - every tranche has a milestone or an evidence item;
  - learners belong to the cohort and are no more than the agreement funds;
  - the reference is unique per funder;
  - files must be `grant_document` files.
- **Permissions:** `funding.view` (owner and centre managers) and `funding.manage` (owner only), because stipend and bank data follow in 9b.

## Consequences

- Claims in 9c take their learner list and amounts straight from `GrantProgress`, so the claim can't disagree with the records.
- A SETA milestone outside the list needs an evidence item, or a new key in code. We'd rather add keys as real agreements show them than build a rule editor now.
- Per-learner amounts are rounded to cents once, per tranche.
