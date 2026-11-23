# Sprint 9: Funding claims and rejection fixes (close report)

**Status:** Build complete. Parts 9a to 9d merged with CI green. Items that wait on accounts, documents or people outside the code are marked *external*.

**Pull requests:**
- #42 grant agreements, tranches and computed milestones
- #43 stipends from the signed register, bank file and proof of payment
- #44 tranche claims, packs and funder queries
- #45 To-fix inbox, learners' one-tap fixes, online tests linked to summative instruments, and close (this report)

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. Record the MoA: funder, learners, amount, tranches with milestones | ✅ | Cohort → Funding → Add a grant agreement; demo agreement `CETA/DG/2026-27/0142` |
| 2. Each tranche's status from the records, with what is missing per learner | ✅ | Agreement page: learners met, claimable amount and each learner's blockers in plain words (ADR-034) |
| 3. Stipends proposed from the signed register, confirmed, bank file, proof of payment; learner sees status | ✅ | Stipends card → month page; app "My stipend" (ADR-035) |
| 4. Claim pack per tranche | ✅ | Claim tranche → claim page → Claim pack (invoice PDF, schedule, MoA, evidence, registers, SoRs, stipend proofs, README) (ADR-036) |
| 5. Funder queries recorded, linked to the fix, claim sent again | ✅ | Claim page: Record a query → Mark fixed → Mark sent again; demo claim queried by CETA |

## 2. Scope checklist (spec §2 and the sprint sheet's Sprint 9 row)

| Item | Status | Where |
|---|---|---|
| Funders and grant agreements | ✅ | ADR-034 |
| Tranches, milestones from records, evidence items | ✅ | ADR-034 |
| Stipend rule, monthly runs, bank file, proof, learner status | ✅ | ADR-035 |
| Claims: gapless invoice numbers, pack, queries, resend, paid or rejected | ✅ | ADR-036 |
| CETA plus generic template | ✅ (title only) | §7.3 |
| Rejection inbox and fix routing (sheet) | ✅ | "To fix" on the provider home: QCTO rejections, funder queries, registers, EISA closing, tranches, stipends (ADR-037) |
| Dashboards (sheet) | ✅ (funding) | Ready to claim, claimed and unpaid, paid this year, stipends held |
| One-tap fixes in the app (sheet) | ✅ | Home cards: bank details, EISA declaration, QCTO profile problems |
| Email, SMS, WhatsApp gateways managed by Super Admin (sheet) | ✅ (earlier) | Built in Sprints 1–2 (Messaging drivers, integrations); nothing new needed |
| Push and WhatsApp notifications (sheet) | ✅ push / ⏳ WhatsApp | New push types `stipend`; WhatsApp templates await Meta approval (*external*) |
| Linking online tests to summative instruments (carried from Sprint 7) | ✅ | ADR-037 |
| 3D simulators 2 and 3 (sheet) | ⏳ *external* | 3D squad work |

## 3. Performance

Measured on the demo database with the Bricklayer cohort filled to 300 learners (median of 5 runs):

| Operation | Result | Target |
|---|---|---|
| Milestone progress for an agreement of 300 learners | **50 ms (17 queries)** | 1 s |
| Stipend month proposed for 300 learners | 193 ms | — |
| QCTO file check, 300 learners | 425 ms | 2 s |
| Web initial JavaScript | 122.7 KB gzip | 160 KB |

## 4. Security and POPIA

- **Bank details:**
  - the account number is encrypted at rest and never returned by the API (`•••• last4` only);
  - the audit log records only the last 4 digits;
  - the bank file is made on request, never stored, its downloads are throttled and audited, and names are formula-safe.
- **Claim packs** carry full ID numbers. They are made on request with `funding.manage`, never stored, and every download is audited. ID numbers are not stored on the claim.
- **New permissions:** `funding.view` (owner and centre managers) and `funding.manage` (owner only).
- **Every money change is audited:** stipend changes need a reason (before and after recorded), and a short claim payment needs a note.

## 5. Accessibility

axe (WCAG 2.2 AA): **120 screens, 0 violations**, in CI. New screens:
- funding, agreement dialog and agreement page;
- stipend rule, run page and bank details;
- claim page with its query and fix dialogs;
- the provider home with "To fix".

## 6. Quality

| | Sprint 8 | Sprint 9 |
|---|---|---|
| API tests (Pest) | 378 | **387** |
| Mobile tests | 33 | **35** |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| ADRs | 33 | 37 (ADR-034 to 037) |

## 7. Changes from the spec (for your approval)

1. **No `stipend_rules` table.** The rule lives on the grant agreement (one rule per agreement, as the spec intended).
2. **No bank account verification (AVS).** A wrong account number shows up only when the bank rejects the line. This is a candidate for Phase 3.
3. **The CETA template changes only the title** until TYM shares CETA's claim form (*external*).
4. **The claim invoice has no VAT line and no provider bank details.** It needs TYM's accountant (*external*).
5. **The bank file is a generic CSV.** Bank-specific layouts get added once TYM names its bank (*external*).
6. **Not in the spec but added from the sheet's Sprint 9 row:** the To-fix inbox, funding totals, and the learner one-tap fixes.

## 8. Carried into Sprint 10

- Paper-form upload for the EISA declaration (Sprint 8 §7.2), if wanted.
- WhatsApp templates (*external*: Meta approval). Visual check on a phone (*external*: EAS build).

## 9. Needed from you / TYM

- Approve this report and the Sprint 10 spec (`sprint-10-phase1-complete.md`).
- **TYM:**
  - a real CETA MoA with its tranche schedule and a past claim pack;
  - their bank, for the bank file layout;
  - their accountant's view on VAT for grant claim invoices;
  - a real QCTO reply file;
  - EISA dates and assessment centres.
- **Accounts:** EAS, Apple and Google; the Home Affairs reseller; LAWtrust sandbox; Meta templates; gateway keys.
