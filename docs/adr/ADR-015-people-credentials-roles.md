# ADR-015: Staff credentials, provider roles and personal data

- Status: Accepted
- Date: 2026-11-22
- Sprint: 3b

## Context

Facilitators, assessors, moderators, RPL advisors and mentors must be registered with a
QCTO-delegated quality partner, often a SETA. A registration has a scope and an expiry, and
moderators must also be registered assessors. Providers also need roles that the 17 standard
roles don't cover (a quality officer, for example). SETA reports ask for equity data, which is
special personal information under POPIA.

## Decision

1. **A credential register, per provider** (`staff_credentials`).
   - Each entry records the role, the registering body, the number, its scope (catalogue entries), start and expiry dates, and the registration letter.
   - It counts only when **verified by someone else**, with the letter uploaded. Changing any checked field sends it back for checking.
   - A **moderator** is verified only where the person is also a verified, in-date **assessor** for every qualification in scope.
   - `CredentialService::eligibility()` answers "may X assess Y on date D?" with a plain reason. Cohorts (3e) use it to refuse out-of-scope assignments.
   - A daily job emails the holder and the owners 90, 60 and 30 days before expiry, and when it expires. Renewing restarts the reminders.
   - Staff add their own registrations; letters use a `self` file purpose (uploader, or `credentials.view`).
2. **Provider-built roles live in the same catalogue as the standard roles.**
   - `custom_roles` rows (keys `custom:<ulid>`) are protected by row-level security and merged into `Catalogue::roles()` through a request-scoped loader. Every existing permission check therefore treats them the same as standard roles.
   - A provider's roles may contain only permissions an owner holds. Platform permissions and the right to build roles are excluded.
   - MFA is required whenever a role can change people, money or records.
   - Only owners hand them out.
   - A role that people hold cannot be deleted, or moved between provider-wide and one-centre.
   - The agreed 17 standard roles are unchanged.
3. **Personal data.**
   - **Identity:** the SA ID number is checked (Luhn, date and citizenship digits), sets the date of birth, and is unique across the platform (by hash). It is stored encrypted and shown masked. Once recorded, only the provider can change it.
   - **Profiles** (`person_profiles`): address, home language and emergency contact are kept per provider, because purposes differ under POPIA.
   - **Equity data** (gender, race, disability):
     - Kept only after explicit, versioned consent, and encrypted at rest.
     - Withdrawing consent deletes it.
     - Visible only to the person and to `people.manage`.
     - A gender different from the one the ID was issued with is allowed. It produces a notice only, and the ID's gender digits are never stored.
4. **Bulk import.**
   - CSV import with a dry run that explains each row: names, contact, role (by name or key, including provider roles), centre, ID checksum, and duplicates within the file.
   - Invitations go out only when every row is clean.
   - An ID number in the file travels encrypted with the invitation and is applied on acceptance if nobody else holds it.

## Consequences

- Cohorts and assessments can rely on a single eligibility check.
- Two bugs were found by tests while building this, and are fixed:
  - Imported ID numbers were silently dropped (the invitation model did not allow those fields).
  - A test's ID number had an invalid check digit, which the Luhn check correctly rejected.
