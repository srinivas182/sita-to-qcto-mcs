# ADR-019: Applicants, intakes and applications

- Status: Accepted
- Date: 2026-11-23
- Sprint: 4a

## Context

Applicants arrive from a WhatsApp link or a poster, mostly on a phone and often without
email. They must be able to apply without an account. The provider must not be billed for
them, and must not see duplicate or invented identities. aXcelerate starts with a short
form and collects more later; its forms ran through a WordPress plugin and assume
Australian identifiers.

## Decision

1. **Intakes** publish a cohort for applications.
   - Each has dates, places (the cohort's size by default), an age range and up to 15
     questions (yes/no, choice, words).
   - Each gets a random 8-character public code: `/join/{code}` on the provider's address,
     shown with a QR code and a WhatsApp share.
   - Publishing is refused if the qualification won't take enrolments on the cohort's
     intake date. This is the same date rule as the cohort check.
   - Applications must close before the cohort ends.
2. **Applying without an account:**
   - A one-time PIN to the phone, by WhatsApp then SMS, proves the number.
   - Limits are per phone: one PIN per 30 seconds, 5 per hour. Per IP address the limit
     is a generous 30 per hour, because applicants at an open day share the provider's
     Wi-Fi.
   - The SA ID is checked (Luhn, date, citizenship digit) in the browser and again on the
     server. Passports need a country and date of birth.
   - The age rule uses the date of birth from the ID.
   - The person is found or created **across the platform**. An ID number already held by
     someone with another phone, or a phone that belongs to someone with a different ID
     number, is refused, never merged. Duplicates are resolved by support, as in Sprint 1.
3. **Applicants are members with no role.** They can sign in to the app and follow their
   application. The billing meter counts only active members with the *learner* role, so
   applicants cost the provider nothing until they enrol (4c).
4. **The pipeline:** new → shortlisted / waiting list / not successful, with reopen.
   - The waiting list is numbered.
   - "Not successful" needs a reason, which goes to the applicant by WhatsApp or SMS.
   - One application per person per intake.
   - Offers, acceptance and enrolment come in 4c.
5. **Permissions:** `applications.view` and `applications.manage` (owner and centre manager,
   limited to their own centres through the cohort). Host employers, who hold
   `learners.view`, do not see applicants.

## Consequences

- The public form asks only what the provider needs to decide. Documents come in 4b, and
  consent and agreements come with the offer in 4c.
- A provider shares one link per intake. A closed intake still shows its page, so old
  links don't break, but takes no applications.
