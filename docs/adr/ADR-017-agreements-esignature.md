# ADR-017: Agreements and electronic signatures

- Status: Accepted
- Date: 2026-11-23
- Sprint: 3d

## Context

Every workplace placement needs a three-way agreement between learner, host employer and
provider. Some providers also use an employer memorandum of understanding and a learner
enrolment agreement. Today these are printed, signed by hand, scanned and lost. Leaders use
DocuSign or Adobe Sign (aXcelerate, for example). Those send by email, which learners in
townships often don't read, keep data offshore, and leave the provider to assemble evidence.

The Electronic Communications and Transactions Act (ECTA), section 13:
- **Agreed between parties** (s13(3)): where the parties haven't agreed a type of signature,
  a signature is valid if the method identifies the person, shows their approval, and is
  reliable enough for the purpose. This covers the agreements above.
- **Required by law** (s13(1)): where a law requires a signature, only an **advanced
  electronic signature** from an accredited provider will do. Some SETAs may require one.
  This was decided in Sprint 3 §9: a yes/no switch per agreement, with a default per
  provider.

## Decision

1. **Wording is versioned per provider.** A draft can change; publishing freezes it.
   Editing a published version starts the next draft, and publishing retires the old
   version. Agreements keep the version they were sent with. Standard wording for three
   kinds (workplace, employer MoU, learner) is a starting point that providers must check.
2. **Fields are filled from records**, never typed: employer CIPC and levy numbers,
   workplace address and approval, mentor, qualification and SAQA ID, dates. Unknown fields
   are refused when wording is saved, and missing values are refused when an agreement is
   sent. A workplace agreement can only be sent for a site whose approval is in date and
   covers the qualification (ADR-016).
3. **The text is frozen when sent** and identified by its SHA-256. Every signature is made
   against that fingerprint.
4. **Standard signing (`simple`)**, used by default:
   - Each signer gets a link: a 48-character token, stored only as a hash, sent by
     WhatsApp or SMS and/or email. Sending again issues a new link and the old one stops
     working.
   - The signer reads the agreement and asks for a **one-time PIN** (6 digits, valid 10
     minutes, at most 5 wrong tries, at most 8 PINs). It goes by WhatsApp, then SMS, then
     email.
   - They type their **full name** (it must match), tick their **consent**, and sign.
   - The record keeps the time, IP, device, PIN channel and masked destination, and the
     text fingerprint.
   - When everyone has signed, the PDF is **sealed**: the text, then a page of signatures
     and evidence with a **QR code** to `/verify/{code}`. The PDF's own SHA-256 is stored.
5. **Verification is public, and shows no document**: title, provider, who signed and
   when, and both fingerprints. A person can check a copy in the browser (SubtleCrypto);
   the file is never uploaded. Signers download their copy only through their own link,
   which is sent fresh on completion.
6. **Advanced signing (`advanced`)** goes through a `SignatureProvider` interface.
   - LAWtrust SigningHub is the first provider: one platform connection, held by the JV,
     tested from Settings.
   - Sending creates a package, uploads the PDF, adds the signers and starts the workflow.
     SigningHub emails the signers.
   - A 15-minute job checks progress and keeps the signed PDF that LAWtrust returns.
   - Advanced agreements can't be sent until LAWtrust is connected, and every signer
     needs an email address.
7. **Follow-up**: reminders on day 3 and day 7, then the agreement expires after the
   provider's chosen period (30 days by default).
8. Permissions `agreements.view` and `agreements.manage` (owner and centre manager). All
   tables are protected by row-level security. Signing links work only on the provider's
   own address.

## Consequences

- Learners sign on a phone, with the PIN on WhatsApp, which they already use. That is the
  gap the leaders leave.
- Standard signing is not a qualified or advanced certificate signature. The PDF is
  protected by its fingerprint and the evidence trail, not by a cryptographic signature
  inside the PDF. Where that matters, the advanced switch applies.
- **The SigningHub endpoints follow the published v4 guide but haven't been tried against
  a LAWtrust sandbox.** This must be done before LAWtrust is enabled live (open item for
  TYM/JV: a LAWtrust account).
- Mobile in-app signing with a push notice comes with 3e. It uses the same API.
