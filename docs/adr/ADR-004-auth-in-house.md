# ADR-004: Authentication built on Laravel, not an external identity service

**Status:** Accepted (approved 22 Nov 2026)

## Context
Per-user pricing of Clerk/Auth0/WorkOS becomes very expensive with millions of learners; POPIA favours South African hosting; we need SA ID checks, WhatsApp PINs and offline sign-in.

## Decision
Laravel Sanctum (cookies for web, device-bound tokens for mobile), WebAuthn passkeys, TOTP MFA, phone PIN by WhatsApp with SMS fallback.

## Consequences
We own security-critical code, so it is covered by thorough tests and an external penetration test before go-live.
