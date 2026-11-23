# Sprint 8: Zero-rejection engine and EISA registration (close report)

**Status:** Build complete. Parts 8a to 8d merged with CI green. Items that wait on accounts, documents or people outside the code are marked *external*.

**Pull requests:**
- #38 rule engine for the QCTO file; export refused with errors
- #39 the QCTO's reply loaded back onto learners
- #40 EISA calendar, registration, declaration and packs
- #41 the app (EISA card and declaration, QCTO profile fields), performance and close (this report)

## 1. Demo script (spec, top)

| Step | Result | Evidence |
|---|---|---|
| 1. Check the cohort's QCTO file; every problem links to its fix; export only without errors | ✅ | Cohort → QCTO file: errors and warnings per learner with a Fix link; export refused while errors remain; warnings acknowledged and audited with the rules version (`LEISA-2024.1`) |
| 2. Load the QCTO's answer; rejected learners show as errors with the reason | ✅ | Submission log → Load reply (xlsx or csv, or mark by hand); `Q-QCTO-REJECTED` until marked fixed |
| 3. EISA calendar and countdown; warnings in time | ✅ | Cohort → EISA: dates with their source, countdown; emails at 30, 14 and 3 days |
| 4. Assessment centre and foundational learning with evidence; learner signs the declaration in the app | ✅ | Registration dialog; FLC evidence for 01/03/08, RPL date for 02; declaration on My results (web and app) |
| 5. Registration pack and registration recorded | ✅ | ZIP per learner and per cohort (ID copy, SoR, declaration, QCTO proof, README of gaps); Register / Withdraw |

Demo data: the Bricklayer qualification has two published EISA dates (75 and 180 days ahead). Bongani has no Statement of Results yet, so his row shows why he can't be registered.

## 2. Scope checklist (spec §2)

### Backend
| Item | Status | Where |
|---|---|---|
| Field, cross-field and accreditation rules; codes, messages, fix links; versioned | ✅ | `QctoRules`, ADR-032 |
| Checks on save (profile shows QCTO problems) and on the whole file; export refused with errors | ✅ | ADR-032 |
| QCTO reply import | ✅ | `QctoResponse`, ADR-032 |
| EISA calendar with provider override; countdown; 30/14/3-day alerts | ✅ | ADR-033 |
| Registration: centre, FLC and evidence, declaration, record; packs per learner and cohort | ✅ | ADR-033 |
| Assessment centre's own accreditation dates checked | ⏳ | We record the centre's number and name; there is no register of assessment centres to check against yet (§7) |

### Web
| Item | Status |
|---|---|
| QCTO file check screen, export, submission log, reply loading, mark fixed | ✅ |
| Cohort EISA screen: dates, countdown, registration, packs, add a date | ✅ |
| Learner declaration on My results | ✅ |

### Mobile
| Item | Status |
|---|---|
| Learner: EISA date and venue, sign the declaration | ✅ |
| Learner profile fields for the QCTO file (title, middle name, nationality, citizen and work status, province, address line 2, Stats SA area, postal address) with the same rule results | ✅ |
| Push for EISA registration opens My results | ✅ |

## 3. Performance (spec §5 and carried from Sprint 7)

Measured on the demo database with the Bricklayer cohort filled to 300 learners (median of 5 runs):

| Operation | Before | After | Target |
|---|---|---|---|
| QCTO file check, 300 learners | 649 ms | — | 2 s |
| Results grid, 300 learners | 856 ms (901 queries) | **23 ms (4 queries)** | 300 ms |
| Statement readiness, 300 learners | one set of queries per learner | **31 ms (7 queries)** | — |
| EISA cohort screen, 300 learners | 16 ms (7 queries) | — | — |
| Web initial JavaScript | 122.5 KB gzip | — | 160 KB |

The grid and readiness now load results for the whole cohort at once (`modulesFor`, `readinessFor`). The demo qualification has 3 modules, not 20; the new queries don't grow with the number of modules. Recording a single decision was not timed separately (one row and its checks).

## 4. Security and POPIA

- **Packs hold ID copies.** They are built on request, never stored, and every download is audited (`eisa.pack_downloaded`).
- **FLC evidence** is a controlled file purpose: uploaded by `cohorts.manage`, viewed only with `learners.view`.
- **The declaration** records the time, the name typed and the text version. Changing the EISA date clears it.
- **Exports with warnings** are audited with the rules version and who acknowledged them.
- **The QCTO reply** is matched by ID number, or alternate ID and last name. Rows that don't match are counted and shown, never guessed.

## 5. Accessibility

axe (WCAG 2.2 AA): **110 screens, 0 violations**, in CI. New: the cohort EISA screen and the add-date dialog.

## 6. Quality

| | Sprint 7 | Sprint 8 |
|---|---|---|
| API tests (Pest) | 367 | **378** |
| Mobile tests | 31 | **33** |
| Static analysis | Larastan L8, ESLint, TS strict | same, all clean |
| ADRs | 31 | 33 (ADR-032, 033) |

## 7. Changes from the spec (for your approval)

1. **The declaration is stored on the EISA registration**, not run through the Sprint 4 agreements engine. A one-paragraph declaration doesn't need the engine's signer flow. It is versioned and audited the same way.
2. **No SMS-link signing yet.** Learners sign in the app or on the web. Learners without either can sign a paper form, which the provider uploads (*to add in Sprint 9 if you want it*).
3. **Assessment centres aren't checked against their own accreditation.** There's no register of assessment centres yet; we record the number and name.
4. **The QCTO reply format is our best reading.** We read xlsx or csv and find the ID and reason columns by their headings. Once TYM receives a real reply we'll adjust (*external*).
5. **Stats SA area check covers the province digit only.** The full list of area codes is not published in a form we can load.
6. **Rules live in code with a version**, not in a `qcto_rules` table edited on screen. A new QCTO version is a release. That's safer while the rules are still being proven.

## 8. Carried into Sprint 9

- Linking task-engine tests to summative instruments (from Sprint 7).
- Paper-form upload for the declaration, if approved (§7.2).
- Visual check on a phone (*external*: EAS build). ffmpeg on production workers.

## 9. Needed from you / TYM

- Approve this report and the Sprint 9 spec (`sprint-09-funding-claims.md`).
- **TYM:**
  - A real QCTO reply file (any cohort).
  - The EISA dates for CETA qualifications and the assessment centre(s) TYM uses.
  - A CETA discretionary grant agreement (MoA) with its tranche schedule, and a past claim pack.
  - How TYM pays learner stipends today (bank, batch file, proof of payment).
- **Accounts:** EAS, Apple and Google; the Home Affairs reseller; LAWtrust sandbox; Meta templates; gateway keys.
