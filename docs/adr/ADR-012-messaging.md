# ADR-012: Messaging through interchangeable providers with cross-vendor failover

**Status:** Accepted (Sprint 2c, 22 Nov 2026)

## Context
- Sign-in codes must arrive even when one provider is down.
- WhatsApp authentication templates suit South Africa better than SMS. Several SA SMS
  providers are in use, and the JV wants the Super Admin to choose and order them in
  Settings.
- Message costs must be visible per training provider so they can be recharged.

## Decision
- **One `MessagingDriver` interface:**
  - `sendText`, `sendCode` (WhatsApp sends the authentication template; SMS sends text)
    and `receipts`.
  - Drivers: Meta WhatsApp Cloud API, Clickatell One API, BulkSMS, SMSPortal, Panacea
    Mobile, Infobip and Twilio.
- **`MessageService` failover:**
  - Tries enabled providers of a channel in the admin's order. The OTP dispatcher tries
    channels in order: WhatsApp, then SMS.
  - Every attempt is a row in `messages` (RLS per provider) with provider, status,
    provider ID, cost (the admin's contracted price per message) and the attempt it
    replaced.
  - The message text is never stored. The number is kept only as a masked label and a
    keyed hash, so it can still be searched.
- **Callbacks are always verified:**
  - Meta: `X-Hub-Signature-256` HMAC-SHA256 with the app secret, plus the GET
    verify-token handshake.
  - Twilio: `X-Twilio-Signature` HMAC-SHA1.
  - Providers that don't sign get a callback URL with a secret key derived from the
    app key and the integration. Settings shows each provider its URL to paste.
- **Email:** the `platform` mailer sends through the SMTP or SES service switched on
  in Settings, with its sender address. It is purged when the setting changes, so
  long-lived workers pick it up.
- **Services resolved per send:** the OTP channel resolves its services each time it
  sends, because the dispatcher is a singleton but the tenant context belongs to a
  request or job.

## Consequences
- Adding a provider means one driver class and a catalogue entry.
- WhatsApp is used only through approved templates. Free-form business messages need a
  customer-opened 24-hour window. Utility templates for billing and learning
  notifications are added as they are approved.
- Local development and tests fall back to the log channel when no provider is on.
  Production does not: an unsent code is reported as not sent.
