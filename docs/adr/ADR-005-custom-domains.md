# ADR-005: Cloudflare for SaaS for custom domains

**Status:** Accepted (approved 22 Nov 2026)

## Context
Hundreds of providers will bring their own domains and need automatic SSL.

## Decision
Free subdomains under the platform domain; custom domains through Cloudflare for SaaS custom hostnames (automatic certificates, firewall, South African edge). Tenants are resolved from the request host via a Redis cache.

## Consequences
Certificate management is outsourced; local development uses mkcert.
