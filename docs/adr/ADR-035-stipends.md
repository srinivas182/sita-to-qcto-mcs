# ADR-035: Stipends from the signed register; bank file, not payments

- Status: Accepted (Sprint 9b)
- Date: 2026-11-23

## Context

Unemployed learners on SETA learnerships get a monthly stipend paid through the provider or employer. SETAs pay it only for months the attendance register confirms, and BANKSETA asks for proof that stipends were paid before it pays the provider's invoice. Late or wrong stipends are a known cause of learner unrest; the Services SETA set up a unit in 2026 to tackle stipend delays. Approved with the Sprint 9 spec:

- decision 2: the platform proposes and records, and never moves money;
- decision 3: full at 80% attendance or more, pro-rata below, held under 50%, changeable per funder.

## Decision

- **The rule lives on the grant agreement:** monthly amount, full-stipend level, hold level, and pro-rata on or off.
- **Monthly run** (`stipend_runs`, one per agreement and month). It is proposed only from that month's register once the facilitator has signed it (`complete` or `submitted`). Each learner line (`stipend_lines`) follows `StipendService::decide`, in this order:
  1. not on the register → hold;
  2. no attendance → hold (workplace-only months count as 100%);
  3. learner disputed or hasn't signed the register → hold;
  4. below the hold level → hold;
  5. otherwise full or pro-rata;
  6. no bank details → hold, with the amount kept so it can be paid once added.
- **Changes need a reason.** A changed line keeps its amount and reason when the month is proposed again. Each change is audited with before and after.
- **Confirm** locks the month and re-checks bank details.
- **The bank file** is a CSV most SA business banking imports: recipient, branch code, account number, account type code (1, 2 or 3), amount, references. It is made on request, never stored, its downloads are throttled and audited, and names are protected against spreadsheet formula injection.
- **Mark paid** needs the bank's proof of payment. Each learner then gets a push saying they were paid, or why their stipend is held.
- **Bank details** (`learner_bank_accounts`):
  - entered by the learner in the app or by the provider;
  - the account number is encrypted at rest and never returned by the API (only `•••• last4`);
  - universal branch codes are filled in for the main SA banks;
  - the audit log records only the last 4 digits.
- **Learners** see confirmed and paid months only, not drafts.

## Consequences

- Stipends match the signed register by construction, so the register and the proof of payment become claim evidence in 9c.
- We don't check account numbers against the banks (no AVS/CDV yet), so a wrong number shows up only when the bank rejects the line. Bank account verification is a candidate for Phase 3.
- Bank-specific file layouts can be added next to the CSV when a provider's bank needs one.
