# ADR-014: One shared qualification catalogue

- Status: Accepted
- Date: 2026-11-22
- Sprint: 3a

## Context

Every provider needs the same QCTO and SAQA facts: modules, credits, NQF levels and
gazetted dates. In other systems each provider types these in again, the copies drift
apart, and nobody enforces the deadlines for legacy qualifications. Old and new
qualifications are also built differently:
- **Occupational qualifications** have knowledge (KM), practical skill (PM) and work
  experience (WM) modules, with codes like `641201001-KM-01`.
- **Pre-2009 legacy qualifications** are made of unit standards in fundamental, core and
  elective groups, each group with its own credit rule.

## Decision

1. **The platform owns one catalogue.** `qualifications` and `qualification_modules` are
   platform rows with two row-level security policies:
   - Anyone may *read* published entries.
   - Only the platform context may *write* any entry.

   A single FOR ALL policy would have let readers delete rows, so there are two.
   Providers never copy an entry: they adopt it (`tenant_qualifications`) and add their
   own modes, price and notes.
2. **Both structures are modelled.**
   - Each module row has a kind: KM, PM, WM or US.
   - Each row also has a group: compulsory or elective for occupational qualifications; fundamental, core or elective for legacy ones.
   - Legacy qualifications also carry credit rules, e.g. `{"fundamental":59,"core":21,"elective_min":40}`.
3. **Checks before publishing.**
   - *Errors* block publishing: no identifier, no modules, a legacy qualification without its last enrolment date, dates out of order, or credit rules not met.
   - *Warnings* are left to the editor: a missing component, or totals that differ from the qualification's credits.
4. **Paste import with a preview.**
   - Modules are pasted from the curriculum document or a spreadsheet.
   - Each bad line is explained, and nothing is saved until the editor accepts the preview.
   - Saving replaces the module list and bumps the version of a published entry.
5. **Dates are enforced and announced.**
   - An entry's enrolment state is open, closing (within 180 days), closed, ended or withdrawn.
   - Providers cannot adopt an ended qualification. They can adopt a closed one, with a warning, so learners already enrolled can finish.
   - A daily job emails provider owners 180, 90 and 30 days before enrolment closes, and when it closes, naming the replacements.
6. **Centre scope links to the catalogue** (`centre_qualifications`). SAQA IDs from the accreditation letter link automatically when the provider adopts that qualification, and they are offered as suggestions on the Programmes page.
7. **Two editing routes.**
   - The JV compliance desk role keeps the catalogue (the agreed 17 roles are unchanged).
   - Providers submit suggestions, and are emailed the answer.
8. **No guessed data.** Seed entries hold only facts checked against SAQA. Entries whose modules are not yet loaded stay as drafts.

## Consequences

- Corrections reach every provider at once, and every change to a published entry gets a new version.
- Live SAQA or QCTO lookups would need a public API; for now, entries are loaded from documents.
