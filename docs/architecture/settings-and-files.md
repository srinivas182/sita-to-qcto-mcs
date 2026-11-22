# Settings, integrations and files (Sprint 2a)

## Settings (Super Admin → Settings)
| Tab | What it holds | API |
|---|---|---|
| Company | JV registered name, registration and VAT numbers, address, invoice prefix, VAT rate, bank details | `GET/PUT /v1/admin/settings/company` |
| Payments | Paystack, PayFast, Peach Payments, Ozow, Yoco, Stitch, iKhokha | `GET /v1/admin/settings/integrations` |
| SMS | Clickatell, BulkSMS, SMSPortal, Panacea Mobile, Infobip, Twilio (ordered fallback) | `PUT …/integrations/{driver}` |
| WhatsApp | Meta Cloud API | `POST …/{id}/enable` · `…/disable` · `…/test` |
| Email | SMTP, Amazon SES | `PUT …/integrations/order` |
| Storage | Local disk, Amazon S3 (any S3-compatible), Azure Blob; one on at a time | `DELETE …/{id}` |

All of these need `platform.settings.manage` (Super Admin) and run in the platform
context. See ADR-010.

## Upload flow
```
client ── POST /v1/files {purpose, name, mime, size} ──► API: checks purpose, type, size, permission
       ◄── {data, upload: {method, url, headers}} ───────  (signed S3/Azure URL, or the API for local)
client ── PUT bytes ──► S3 / Azure / API
client ── POST /v1/files/{id}/complete ──► API: size check → queue ProcessFile
                                           ProcessFile: magic-byte type check → ClamAV → variants → ready
client ── GET /v1/files/{id} (poll) ──► status: ready | rejected | failed
```
- **Paths** never contain the uploaded name:
  `<tenant>/<purpose>/<yyyy>/<mm>/<ulid>`.
- **Public images:** `GET /v1/images/{id}/{variant}` serves only re-encoded WebP
  variants of purposes marked public, cached for a year.
- **Purposes** (`config/files.php`): `tenant_logo` (2 MB, PNG/JPG/WebP, variants 512
  and 128) and `application_document` (10 MB, PDF/JPG/PNG; used in 2d).

## Local development
`make up` also starts ClamAV, MinIO (S3) and Azurite (Azure). Set `FILES_SCANNER=clamd`
to scan locally, or leave `none`, which is accepted only in local and testing.
`make dev` runs a queue worker, which processes uploads.
