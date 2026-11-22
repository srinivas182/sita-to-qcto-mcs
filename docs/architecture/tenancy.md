# Tenancy and data isolation

Sub-sprint 1b · modules `Tenancy`, `Audit`, `Support/Database` · ADR-003, ADR-005

## Model

```
cells (global)  ─┬─ tenants (training providers)
                 │     ├─ tenant_domains   tym.<base>, learn.trainingyoungminds.co.za
                 │     ├─ centres          each with its own QCTO accreditation
                 │     └─ … every tenant-owned table (tenant_id + RLS)
                 └─ audit_logs (partitioned by month, append-only)
```

## Three contexts

| Context | Set by | Database sees |
|---|---|---|
| **tenant** | `ResolveTenant` middleware (host or `X-Tenant`), queued jobs, `runAs()` | that tenant's rows only |
| **platform** | `runAsPlatform()` in trusted services after their own authorisation | all rows |
| **none** (default) | start of every request / job, end of every request | no tenant rows |

The context is pushed to PostgreSQL as `app.tenant_id` and `app.context` and read by
one policy per table. A forgotten context fails closed: queries return nothing and
inserts are rejected.

## Adding a tenant-owned table (checklist)

1. Column `tenant_id` (`ulid`, FK to `tenants`, indexed first in composite indexes).
2. In the migration: `Rls::protect('your_table');`
3. Model: `use BelongsToTenant;` (scope + auto-fill + cross-tenant write guard) and,
   for business records, `use Auditable;`.
4. Nothing else. The test `forces row-level security on every table that has a
   tenant_id column` fails CI if step 2 is missed.

## Request flow

1. Host normalised (case, port, trailing dot).
2. Platform hosts (`<base>`, `www`, `admin`, `api`) → `X-Tenant` header or no tenant.
3. Otherwise `tenant_domains.hostname` lookup; custom domains route only after DNS
   verification.
4. Cached in Redis as raw tenant attributes (10 min; misses 60 s). Measured warm
   resolution: **~0.1 ms** (budget 2 ms). Cache entries are dropped when a tenant or
   domain changes, so suspension takes effect on the next request.
5. Suspended tenant → 403 `tenant_suspended`; unknown host → 404 `tenant_not_found`.
6. Context cleared on terminate (safe for Octane).

## Queues

Every job payload carries the dispatching context. Workers run the job in it and
restore the previous context afterwards (sync jobs inside a request do not change the
request's tenant). Jobs for a suspended or deleted tenant are not run
(`TenantUnavailable`).

Note: `Job::dispatch()` returns a `PendingDispatch` that dispatches when it goes out of
scope. Inside `runAs(fn () => Job::dispatch())` that is *after* the closure returns, so
use the statement form `runAs($t, function () { Job::dispatch(); })`.

## Custom domains

1. `addCustomDomain` → records shown to the TP: `CNAME <host> → edge.<base>` (or A
   records to edge IPs for apex domains) and `TXT _skills-challenge.<host> = token`.
2. `check` (live, repeatable) → DNS verified → certificate requested at the edge
   (Cloudflare for SaaS custom hostnames; `log` driver locally).
3. Certificate active → may be made primary. Ownership, once proved, is not revoked by
   a temporary DNS error. Edge outages never break the set-up screen.

Config: `CUSTOM_HOSTNAME_PROVIDER=cloudflare`, `CLOUDFLARE_ZONE_ID`,
`CLOUDFLARE_API_TOKEN`, `PLATFORM_EDGE_HOSTNAME`, `PLATFORM_EDGE_IPS`.

## Cells

`CellPlacement` puts a new tenant in the least-loaded active shared cell with spare
capacity. Dedicated cells (large clients) are chosen explicitly. `placeInCell` records a
move; moving data between cells is an operations runbook (Sprint 18).

## Audit log

- Range-partitioned by month; `audit:partitions` runs daily and keeps 3 months ahead;
  a DEFAULT partition catches anything else. Every partition has its own RLS policy.
- A trigger rejects UPDATE and DELETE for everyone, platform included.
- `Auditable` records created / updated (from → to) / deleted; hidden and
  `$auditRedact` attributes are stored as `[redacted]`.
- Entries made in the platform context are flagged `meta.platform = true`, so a TP can
  see when the platform acted in its space.

## Database role

The application connects as a role that is **not** a superuser and has **no BYPASSRLS**
(checked by a test). Migrations run as the same role; tables are owned by it and RLS is
FORCED so ownership grants no bypass.
