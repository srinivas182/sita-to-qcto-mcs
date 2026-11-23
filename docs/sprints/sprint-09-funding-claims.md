# Sprint 9: Funding claims and rejection fixes

**Dates:** 15 to 26 March 2027 (sprint sheet row 18). **Phase 1: SaaS compliance core.**

**Demo script (what TYM sees at the end):**

1. The provider records a SETA grant agreement (MoA) for a cohort: the funder, the number of learners, the amount per learner and the tranche schedule. Each tranche has milestones, such as learners registered, three months of attendance registers, the logbook signed off, Statements of Results issued, and EISA registration.
2. The platform shows each tranche's status: which milestones are met, by which learners, and what evidence is still missing. Nothing is claimed by hand-counting.
3. Learner stipends: the provider sets the monthly amount and the attendance rule. Each month the platform proposes the stipend list from the signed registers. The provider confirms it, exports the bank batch file, and uploads the proof of payment. Learners see their stipend status in the app.
4. When a tranche is ready, the provider downloads the claim pack: the claim schedule, the learner list, an invoice, and the evidence (registers, sign-offs, SoRs, proof of stipend payment) in one ZIP.
5. The SETA's queries or rejections are recorded against the claim. Each one links to the learner or document to fix, and the claim is sent again.

## 1. Where the market stands (Nov 2026)

| | What leaders do | Weakness | Our answer |
|---|---|---|---|
| SETA processes | merSETA: a claim form per tranche once milestones are reached, saved as PDF, signed and emailed ([merSETA](https://www.merseta.org.za/wp-content/uploads/2026/07/merSETA_DG-Claim-Guideline.pdf)). BANKSETA pays invoices only with supporting documents, including proof that stipends were paid to unemployed learners ([BANKSETA](https://bankseta.org.za/wp-content/uploads/2026/02/DISCRETIONARY-GRANT.pdf)). TETA asks for signed monthly attendance registers, quarterly progress reports and close-out certificates ([TETA](https://www.teta.org.za/index.php/maritime/item/download/257_8be77624fa2a7bec3350a32baf4d8e55)) | Each SETA has its own form; providers assemble evidence by hand | **Milestones computed from data we already hold** (registers, logbook, SoRs, EISA); one claim pack per tranche; SETA templates as data |
| SETA portals | Services SETA LMIS handles enrolment, achievements and monitoring on the SETA side ([LMIS](https://lmis.servicesseta.org.za)) | The provider still keeps the evidence and the claim history | We keep the provider's side and export what the portal asks for |
| SA provider software | Wemasoft and similar LMSs centre on learning, PoEs and SETA/QCTO reporting ([Wemasoft](https://wemasoft.co.za/lms)) | We found no published tranche or stipend tracking | **Claims, stipends and evidence in the same record** as attendance and results |
| Stipends | SETAs pay stipend money through providers or employers; late stipends cause unrest, and the Services SETA set up a unit to tackle stipend delays ([Mail & Guardian](https://mg.co.za/?p=690146)) | Spreadsheets; no link between registers and pay | **Stipend list proposed from signed registers**, bank batch export, proof of payment held, learner sees status |

## 2. Scope

### 2.1 Backend (`apps/api`)
- **Funders and grant agreements:** funder (SETA, employer, other); agreement per cohort or per set of enrolments; learner count, amount per learner, co-funded or fully funded, disability allowance; dates; signed MoA file.
- **Tranches and milestones:** tranche number, share or amount, milestones chosen from a fixed list (learners registered; agreements signed; N months of signed registers; logbook signed off; SoR issued; EISA registered; certificate received; custom with evidence upload); due dates.
- **Milestone engine:** works out per learner which milestones are met, from existing records. Shows blockers in plain words with links, like the QCTO check.
- **Stipends:** monthly amount and attendance rule per agreement; monthly run proposed from the signed registers (pro-rata or full by threshold); provider confirms; bank batch file (CSV in the common SA bulk format); proof of payment upload; learner notified.
- **Claims:** a claim per tranche with status (draft, sent, queried, paid, rejected); claim invoice with its own numbering; claim pack ZIP; queries and rejections recorded per learner or document, each with a fix link; resend.
- **SETA templates:** claim schedule columns as data per funder, starting with CETA and a generic layout.

### 2.2 Web (`apps/web`)
- Funding screen per cohort: agreements, tranches, milestone progress, blockers.
- Stipend runs: proposal, confirm, export, proof of payment.
- Claims: pack download, status, queries and fixes.

### 2.3 Mobile (`apps/mobile`)
- Learner: stipend status per month (paid, pending, held and why).
- Linking task-engine tests to summative instruments (carried from Sprint 7) is web and API only.

## 3. Data model (new)

| Table | Key fields |
|---|---|
| `funders` | name, kind, SETA code, claim template |
| `grant_agreements` | funder, cohort, learners, amount per learner, funding kind, starts_on, ends_on, MoA file |
| `grant_tranches` | agreement, number, amount, milestones (json), due_on |
| `stipend_rules` | agreement, monthly amount, threshold, pro-rata |
| `stipend_runs` / `stipend_lines` | month, status, confirmed_by; enrolment, days, amount, reason, paid_at, proof file |
| `grant_claims` / `claim_queries` | tranche, status, invoice number, sent_on, paid_on; learner, problem, resolved_at |

## 4. API (v1, additions)

- `GET/POST /v1/funders` · `GET/POST /v1/cohorts/{id}/grant-agreements` · `GET /v1/grant-agreements/{id}/progress`
- `POST /v1/grant-agreements/{id}/stipend-runs` · `POST /v1/stipend-runs/{id}/confirm` · `GET /v1/stipend-runs/{id}/bank-file`
- `POST /v1/grant-tranches/{id}/claims` · `GET /v1/grant-claims/{id}/pack` · `POST /v1/grant-claims/{id}/queries`
- `GET /v1/me/stipends`

## 5. Performance, security and compliance

- Milestone progress for 300 learners in under 1 second (uses the batch loaders from Sprint 8).
- Bank files hold account numbers: made on request, never stored, downloads audited. Learner bank details stay encrypted and are shown masked.
- Amounts in cents; claim invoices numbered without gaps; every status change audited.
- POPIA: stipend and bank data visible only to a new `funding.manage` permission.

## 6. Out of scope

- Paying stipends from the platform (we produce the bank file; the provider's bank pays).
- Submitting to SETA portals by API (none available); we export.
- Mandatory grants (WSP/ATR) for employers: Phase 3.

## 7. Definition of done

- The demo script runs end to end.
- Tests: milestone rules, stipend proposals (threshold and pro-rata), bank file format, invoice numbering, claim pack contents, queries and resend.
- axe clean; CI green; merged; close report; sprint sheet updated.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Each SETA's claim layout differs | Templates as data; generic layout always available |
| Stipend rules differ by funder | Rule per agreement; provider confirms every run |
| Bank file formats differ | Start with the common CSV bulk format; add bank layouts as TYM needs them |
| Bank details are sensitive | Encrypted, masked, audited, separate permission |

## 9. Decisions (for approval)

1. **Milestones are computed** from the platform's records, with a manual milestone (evidence upload) only for items we don't hold, such as the certificate.
2. **We don't move money.** The platform proposes and records stipends and produces the bank file; the provider pays.
3. **Stipend rule default:** full stipend at 80% attendance or more for the month, pro-rata below, held at under 50% with a reason; each funder can change this.
4. **Claim invoices** use their own numbering (`TYM-CLM-YYYY-NNNNN`), separate from the SaaS billing invoices.
5. **First template:** CETA plus a generic layout; others added as data when a provider needs them.
