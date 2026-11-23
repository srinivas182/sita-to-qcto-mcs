# ADR-036: Tranche claims, gapless numbers, claim packs and funder queries

- Status: Accepted (Sprint 9c)
- Date: 2026-11-23

## Context

A provider claims each tranche from the funder with a claim form, an invoice and evidence. merSETA wants one claim form per tranche, signed. BANKSETA pays invoices only with the supporting documents, including stipend proof. Claims come back with queries ("ID copy not legible", "register not signed by the employer"), and providers lose track of what was asked, what was fixed and which learners were already claimed. Approved with the Sprint 9 spec:

- decision 4: claim invoices use their own numbering, separate from SaaS billing;
- decision 5: CETA plus a generic layout first.

## Decision

- **A claim freezes the learners** who met the tranche at that moment (from `GrantProgress`, ADR-034) with their amounts. A learner can be on only one live claim (draft, sent, queried or paid) per tranche. A later claim on the same tranche picks up only learners who met it since.
- **Disability allowance** is added on request, once per learner per agreement.
- **Numbers** are `<TENANT>-CLM-<YEAR>-<NNNNN>`, assigned when the claim is made, under a lock on the tenant row. Claims are never deleted: a cancelled draft (`void`) or a rejected claim keeps its number, so the sequence has no gaps.
- **Statuses:**
  - `draft` → `sent` → `queried` ↔ `sent` → `paid`;
  - `sent` or `queried` → `rejected`;
  - `draft` → `void`.

  A claim can be sent again only when every query is fixed. Marking paid with a different amount needs a note (short payment). A rejected or void claim frees its learners for a new claim.
- **Queries** are recorded per learner or for the whole claim, with the date raised. Fixing one needs a note of what was fixed. On the web, a learner query links to that learner's record.
- **The claim pack** (ZIP) is built on request, never stored, and every download is audited. It contains:
  1. the claim and invoice PDF, with full ID numbers, provider and centre accreditation, agreement, tranche, milestones, learners, totals and a signature block;
  2. the learner schedule as CSV;
  3. the MoA;
  4. the tranche's evidence files;
  5. the signed registers within the agreement period;
  6. the SoRs, when the tranche waits on them;
  7. the stipend schedule and proofs of payment;
  8. a README that marks anything MISSING.

  The template changes only the title for now (`ceta`, `generic`).

## Consequences

- A claim can't include a learner who hasn't met its milestones, or claim the same learner twice.
- Full ID numbers appear only in the pack, which is made on request for `funding.manage`. They are never stored on the claim.
- SETA-specific claim forms (merSETA's spreadsheet, for example) are a layout on top of the same data, added as templates when providers need them.
- The invoice carries no VAT. Grant payments are treated as the funder's agreement says. This needs confirming with TYM's accountant (*external*).
