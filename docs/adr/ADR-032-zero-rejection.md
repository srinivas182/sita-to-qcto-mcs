# ADR-032: Zero-rejection rules for the QCTO file

**Status:** Accepted · Sprint 8a

## Context

The QCTO receives the Learner Enrolment and Readiness for EISA file by email and does not fix providers' data. Errors are found after submission, and learners miss EISA dates. The UK and New Zealand publish their validation rules with codes, and providers run them before they submit. The QCTO data load specification gives each column's format, code list and the rules between columns, but nothing runs them.

## Decision

1. **A versioned rule set** (`Modules\Enrolment\Qcto\QctoRules`, version `LEISA-2024.1`) runs on every row of the file, on top of the "missing value" findings each row already had:
   - leading spaces; code lists (E, F, G, H, I, J, K, L, M, N, R, AF, AH, AK, AN, AO);
   - national ID (13 digits, check digit), the ID type rule between C, D and E, gender and date of birth against the ID;
   - names, address and email characters; a first address line that is only a number; 4-digit postal codes; digits-only phones;
   - dates as YYYYMMDD, POPIA consent and its date, training ending after it starts;
   - the disability rating only with a disability;
   - the Statement of Results status and issue date, the readiness type, the SoR issued before the EISA date, and the readiness submission's assessment centre (AM) and foundational learning (AO, AP, including the RPLYYYYMMDD form);
   - accreditation: the centre is accredited for the qualification, the accreditation hadn't ended at the start of training, and a warning when it ends before training does.
2. **Each finding** has a stable code, a severity (error or warning), a plain message and where it is fixed: the learner's details (`profile`, `identity`, `consent`), or the `centre`, `qualification`, `cohort`, `statement` or `eisa` screens.
3. **No file leaves with an error.** The workbook download and "mark as sent" are refused while any learner in them has an error. Warnings must be confirmed; the confirmation and the rule version are recorded with the download.
4. **Checked as data is saved:** the profile responses (own and staff views) carry the learner's QCTO problems per course, so they are fixed where they are entered.
5. Rules are code, versioned and tested against the specification's rules. A change to the specification is a new version with its date.

## Consequences

- The readiness columns (AM, AO, AP) become required once a Statement of Results is issued; 8c captures them with EISA registration.
- Stats SA area codes are checked only against the province (first digit). A full list check needs the Stats SA code list loaded (later).
