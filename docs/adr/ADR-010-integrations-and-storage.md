# ADR-010: Integrations catalogue and per-file storage

**Status:** Accepted (Sprint 2a, 22 Nov 2026)

## Context
The JV wants every popular South African payment gateway and SMS provider available.
The Super Admin enters each provider's keys, enables or disables it, and orders them,
all from Settings. File storage must be switchable between local disk, AWS S3 and
Azure Blob, also from Settings.

## Decision
- **One catalogue in code** (`config/integrations.php`) declares every driver: its
  fields, which fields are secret, and its capabilities (`recurring`, `instant_eft`,
  `delivery_receipts`, `direct_upload`, …). Adding a provider means adding a catalogue
  entry and an adapter.
- **One table** (`integrations`, platform-only RLS) holds one row per driver:
  - credentials encrypted with the app key (`encrypted:array`);
  - non-secret values; sandbox/live mode; enabled; priority; last test result.
- **Secrets are write-only.** The API returns only a masked hint (`••••1234`). Leaving
  a secret out of an update keeps the saved one. The audit log records changes with
  credentials redacted.
- **Connection testers** are registered per driver by the module that uses the type:
  - Files registers storage; Billing will register payment; Messaging will register
    SMS, WhatsApp and email.
  - Where a tester exists, enabling runs it first and refuses to enable on failure.
- **Storage** is a `single` type: enabling one store disables the others.
  - Each file records the store it was written to (`integration_id`), so switching
    stores never breaks existing files.
  - A store that still holds files cannot be deleted.
- **Uploads** go straight to S3 or Azure through short-lived signed URLs, so large
  files never pass through the app servers. Local storage receives uploads through
  the API.
  - Every file is checked before use: the type is sniffed from its magic bytes,
    ClamAV scans it (INSTREAM), and image variants are re-encoded.
  - Only re-encoded variants are served publicly, never the original upload.
- **Company details** (`platform_settings.company`) hold the supplier particulars for
  SARS tax invoices.

## Consequences
- Business rules pick drivers by capability and priority, never by name. A new
  gateway therefore needs no change to Billing logic.
- The app key now protects provider credentials too. Rotating it needs a
  re-encryption step (`php artisan key:rotate` with `APP_PREVIOUS_KEYS`).
- Production must run ClamAV (`FILES_SCANNER=clamd`). With no scanner, files fail
  safe: they are never marked ready.
