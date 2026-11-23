# ADR-020: Learner documents and their checks

- Status: Accepted
- Date: 2026-11-23
- Sprint: 4b

## Context

Applicants send IDs and certificates as phone photos. Staff check every one by eye and
often find a blurry photo, an old certification or a mistyped ID number weeks later.
Leaders (aXcelerate) have required-document checklists but no checks on what arrives. The
law sets no expiry for a certified copy: most institutions accept 3 months, and government
accepts 6.

## Decision

1. **Documents** (`learner_documents`) belong to the person, not to one application, so a
   second application reuses them.
   - Each records its type, file, whether it is a certified copy, the date on the stamp,
     and who certified it.
   - It also records the automatic checks and the staff decision.
   - The latest upload of each type counts.
2. **The provider's rules**, per document type: whether a certified copy is needed, and
   how old it may be. The default is 90 days (Sprint 4 decision 1), changeable from 7 to
   730 days. The age is worked out again for **today** whenever it is shown or reviewed,
   so an accepted copy can go stale. It then shows as "Certified too long ago", and a
   new copy is needed.
3. **Picture quality** is measured on the server with GD on every image, and kept with the
   document:
   - size: the short side must be at least 800 px;
   - sharpness: the variance of the Laplacian on a 600 px grey copy must be at least
     150 (readable text scores in the thousands);
   - glare: at most 8% of pixels may be blown out;
   - darkness.
   The browser runs the same size and sharpness check before upload and warns the
   applicant. The app (4e) does the same.
4. **ID number match:**
   - When the app reads the barcode on the back of a smart ID card, the number is compared
     with the person's ID number, and a mismatch blocks acceptance.
   - Without a barcode (a web upload or the green book), staff must tick that the number
     on the document matches before accepting. Who confirmed it is kept.
   - The barcode is read on the phone, so it is a consistency check, not proof. The
     optional Home Affairs check (4d) is the strong one.
5. **Review:** accept, or reject with a reason. An ID document needs the number confirmed,
   and a certification outside the rule can't be accepted. "Ask for what is missing"
   sends one WhatsApp or SMS listing what is missing, rejected or too old.
6. **Applicants sign in after applying.** A public applicant in a browser is signed in
   once their PIN proves the phone, exactly as at sign-in. They can then add documents
   on the same page.
7. **Files:** purpose `learner_document`. The owner can always open their own; others
   need `applications.view`. Host employers can't.

## Consequences

- Offers (4c) are blocked until every required document is accepted and still in date.
- **Learner endpoints use `/v1/learner-applications`.** `/v1/applications/{id}` belongs to
  provider onboarding (Sprint 2), and reusing it silently took over that route. A new test
  now fails the build if two routes share a method and path shape.
