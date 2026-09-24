# 0001 — Tenant isolation: single database with Row Level Security

- **Status.** Accepted
- **Date.** 2026-08-27

## Context

Every tenant-owned table needed a boundary a query could never cross, decided before the schema
itself was written. The candidates were the three usual shapes for multi-tenant storage — database
per tenant, schema per tenant, or a single database with a tenant discriminator column — and the
constraint that actually mattered was `delivery_attempts`: the highest-volume table in the system,
growing per tenant without bound, and already committed to monthly range partitioning rather than
per-tenant partitioning.

## Options considered

**Database per tenant.** The strongest possible isolation — a bug in application code cannot leak
across a connection boundary that does not exist. Rejected: migrations run once per tenant instead
of once, connection pooling degrades as the tenant count grows (one pool per database, not one pool
shared across all of them), and cross-cutting scheduled work — the outbox dispatcher, the
idempotency key pruner — would need to open a connection per tenant on every pass rather than a
single loop over rows it can already see.

**Schema per tenant.** A middle ground: one database, one connection pool, but tenants still cannot
accidentally share a table. Rejected for the same operational reason as database-per-tenant, one
size down — `CREATE SCHEMA` and a full migration run still happen once per tenant, and Postgres has
no equivalent to Row Level Security's session-variable predicate for deciding *which* schema a
query is even allowed to see, so the isolation guarantee would still rest entirely on the
application remembering to set the right search path.

**Single database, `tenant_id` column, relying on the application layer alone.** The simplest
schema, and the one every ORM tutorial demonstrates — a global scope on every model, always
filtering on `tenant_id`. Rejected as the *only* layer: it is exactly one forgotten `withoutGlobalScope`,
one raw query, or one background job that never established a tenant away from a cross-tenant
read, and CLAUDE.md's own rule — "a query must never cross the tenant boundary" — cannot be
satisfied by a convention that a single mistake defeats silently.

## Decision

Single database, `tenant_id` on every tenant-owned table, and three independent layers rather than
one:

1. **Global scope** (`BelongsToTenant`) — every query gets the tenant predicate automatically.
2. **Automatic assignment** — the tenant is stamped on write, from `TenantContext`, never from a
   request field or a fillable attribute.
3. **PostgreSQL Row Level Security**, declared `FORCE` so even the schema owner is subject to it,
   read from two session variables (`postbox.tenant_id`, `postbox.user_id`) kept in step with the
   application's own context.

The third layer is only real if the connection cannot step over it: the application connects as
`postbox_app`, a role that owns nothing and holds neither `SUPERUSER` nor `BYPASSRLS`. Migrations
run on a separate connection, as the schema owner, which is the only role permitted to bypass
`FORCE` RLS at all — and it never serves a request.

## Consequences

Migrations stay simple (one schema, one run) and connection pooling stays viable regardless of
tenant count. High-volume tables partition by month rather than by tenant, which is the axis their
actual query pattern — "everything due right now," "everything from the last hour" — is shaped
around, not the axis isolation happens to use.

The cost is that isolation is now a property to be *proven*, not assumed by construction: a second
layer means a test has to exercise it independently of the first. `RowLevelSecurityTest` does
exactly that — the global scope is deliberately disabled (`withoutGlobalScope(TenantScope::class)`)
and Row Level Security still refuses another tenant's row. That test is what would show this
decision failing: if it ever passes with RLS itself disabled, or if a future migration adds a
tenant-owned table without `RowLevelSecurity::protect()`, the second layer has stopped being real.
`RowLevelSecurityCoverageTest` guards the latter — every table carrying a `tenant_id` column is
asserted to have the policy, not left to a migration author's memory.

One further consequence shapes every piece of scheduled work in the system: `FORCE` means no role,
not even the owner, can express "every row of this table, across every tenant." Background work —
the outbox dispatcher, the idempotency pruner — walks the tenants one at a time
(`RunForEachTenant`), because a query across them does not exist to write.
