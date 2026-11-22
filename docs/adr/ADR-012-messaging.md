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

## Addendum (Sprint 2 close): codes filled in without typing, notices, push

**Android, WhatsApp one-tap.** The app's own Android module (`apps/mobile/modules/code-autofill`)
tells WhatsApp it expects a code just before asking for one (the "handshake"). When the learner
taps **Autofill** on the WhatsApp message, WhatsApp opens the app with the code; the app checks
the message really came from WhatsApp (the attached PendingIntent's creator) and signs in.
Set-up for the Super Admin, once per app signing key:
1. In Meta Business Manager, create the authentication template named in Settings → WhatsApp
   (default `sign_in_code`) with a **one-tap** button: package name `za.co.skillsecosystem.app`
   (as in `app.config.ts`) and the app's signature hash (Settings → About in the app shows it).
2. Without one-tap, the same template with a copy-code button still works: the learner taps
   *Copy code* and then *Paste code from WhatsApp* in the app.

**Android, SMS fallback.** Google's SMS Retriever reads the code with no SMS permission when the
message ends with the app's 11-character hash. Set `ANDROID_SMS_HASH` (the value shown in the
app under Settings → About for the release build). **iOS** offers the code above the keyboard.

**Notices** (application decisions; billing reminders next): WhatsApp through an approved
*utility* template whose body is `{{1}}` (Settings → WhatsApp → Notice template), else SMS.

**Push**: Expo's push service (delivers through Firebase and APNs). The app registers its token
on its device record only after the person turns notifications on; tokens Expo reports as gone
are cleared. `EXPO_ACCESS_TOKEN` is needed only if enhanced push security is on.
