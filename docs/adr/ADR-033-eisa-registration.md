# ADR-033: EISA calendar and registration

- Status: Accepted (Sprint 8c)
- Date: 2026-11-23

## Context

A learner who has a Statement of Results must be registered for the External Integrated Summative Assessment (EISA) before the quality partner's closing date. The QCTO accepts no late registrations. Today providers track dates in spreadsheets, collect foundational learning (FLC) proof by email and build each learner's registration pack by hand. Leading platforms show the date but leave the pack, the declaration and the closing-date chase to the provider.

Decisions approved with the Sprint 8 spec:

2. The platform keeps the EISA calendar; a provider can add or correct a date, with its source.
3. The learner signs the declaration in the app (or on My results on the web).
4. FLC evidence is required for codes 01, 03 and 08; code 02 (RPL) needs the RPL date as `RPLYYYYMMDD`.
5. Registration closes hard at the closing date; the learner moves to the next date.

## Decision

- **Two date tables.** `eisa_calendar` is platform data (no tenant), kept by `platform.catalogue.manage`. `eisa_dates` is per tenant under RLS: a provider's own date, or a correction that points at a calendar row (`replaces_id`). `EisaService::dates()` merges them and the provider's row wins.
- **One registration per enrolment** (`eisa_registrations`, RLS) holding the date, assessment centre, FLC code, number and evidence file, the declaration (time, typed name, version) and a status of `draft`, `registered` or `withdrawn`.
- **Checks at save** (`EisaService::save`): an issued Statement of Results; a date that has not closed (the error names the next open date); FLC rules above; code 06 only for NQF 5 and up; the evidence file must be an `flc_evidence` file. Changing the date clears the declaration, so the learner signs again for the new date.
- **Declaration** is a fixed text with a version (`v1`); the learner ticks it and types their full name, which must match their record. It is stored on the registration, not through the agreements engine (a change from the spec: the engine's signer flow is heavier than a one-paragraph declaration needs).
- **Register** only when nothing is missing; the learner gets an `eisa` push.
- **Registration pack** is a ZIP built on request (ID copy, Statement of Results, signed declaration, proof of QCTO submission, and a README listing anything missing). It is audited and not stored.
- **Closing-date alerts**: `eisa:closing` runs daily at 07:25 and mails the owner and centre managers at 30, 14 and 3 days before closing while learners with a Statement of Results remain unregistered. `cohorts.eisa_alerted_for` stops repeats.
- **QCTO file**: columns AM, AO and AP come from the active registration, so the learner list and the EISA registration never disagree.

## Consequences

- One source for EISA data; the zero-rejection rules (ADR-032) check it before export.
- Calendar dates are only as good as their source; every date shows where it came from.
- The pack depends on the learner's ID document being uploaded; the README makes gaps visible instead of failing.
- Not yet done: the learner's mobile screen for the declaration (Sprint 8d).
