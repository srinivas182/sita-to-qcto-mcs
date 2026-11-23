# ADR-022: QCTO enrolment list, Home Affairs ID checks and applicant retention

**Status:** Accepted · Sprint 4d

## Context

Accredited SDPs must email the QCTO a learner enrolment file for each group of learners: the "Learner Enrolment and Readiness for EISA" Excel sheet, columns A to AJ plus the date stamp in AQ, named `LEISAyyyymmdd-<name on the accreditation letter>`, sent to learnerenrolments@qcto.org.za. The QCTO's FAQ says within 21 days of implementing the curriculum; its enrolment page and the 2023 SDP notice say 21 **working** days after the start of training. The QCTO does not correct data for providers. A second submission (readiness for the EISA) is due at least three months before the EISA.

Leading systems export a learner list but leave providers to discover missing fields when the QCTO rejects the file, and none count down to the deadline.

## Decision

1. **The list is built from data we already hold** (identity, profile, consent, cohort, centre, qualification) and checked per learner. Each missing field names the column and what to fill in; staff fix it in a dialog, and learners can fill it in under My profile. The profile gains: title, middle names, nationality, citizen or resident status, work status, postal address (or "same"), Stats SA area code, and the kind and rating of a disability.
2. **Derived, not stored:** the gender code comes from the SA ID number (the QCTO requires it to match). Nationality, citizen status and immigrant status default from the ID's citizenship digit. Optional equity details the learner did not give go as `U` (race) or `N` (disability), shown as warnings.
3. **Due date:** 21 days from the cohort's start by default (the stricter reading). Providers can switch to working days, which count SA public holidays, Easter and the Sunday rule. Reminders go to owners and the centre manager at 7 and 2 days before, on the day, and once overdue. Each step is sent once (the `alerted_for` pattern).
4. **The file:** an .xlsx with every value as text, in column order A to AQ, written with OpenSpout (small, streaming). "Mark as sent" records the date, a note and the learners included. Later downloads offer only the learners not yet sent.
5. **Home Affairs ID checks:** a connector type `identity`, off unless the platform connects one. Checks are queued (by staff, or for every accepted learner if the provider chooses) and run in one batch at 01:30. Results are compared with the names we hold: verified, mismatch, not found, deceased, or failed (3 tries). `IdentityVerifier` is the adapter interface. Only a test driver ships until the JV picks a reseller.
6. **Retention (POPIA s14):** nightly, an applicant who never enrolled, has no open application and has had no activity for the provider's period (12 months by default, 3 to 60) is removed from that provider: applications, offers, documents and files, ID checks, profile and membership. The platform account stays (they may be with another provider). Consent records stay as evidence. Anyone with a role, or ever enrolled, is never touched. A `--dry-run` counts first.

## Consequences

- Providers see exactly what is missing before the deadline, rather than after a rejection.
- Open: the QCTO "Qualification Id" may need the QCTO's own format rather than the SAQA ID (confirm with TYM). Stats SA area codes are typed in until a lookup is added. Disability ratings are shown as codes. The reseller adapter waits for the JV's choice.
