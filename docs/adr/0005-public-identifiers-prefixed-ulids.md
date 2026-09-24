# 0005 — Public identifiers: prefixed ULIDs

- **Status.** Accepted
- **Date.** 2026-09-11

## Context

Every model's internal primary key is a sequential integer — cheap for Postgres to index and join
on, but wrong to expose. A sequential id in a URL or a response body leaks the table's total row
count and growth rate to anyone who can see two of them, invites enumeration, and — for the
highest-volume tables in the system (`messages`, `delivery_attempts`), already committed to monthly
range partitioning (0001) — ties a value a producer or a dashboard stores forever to the database's
own physical row order, which a repartition or a rebuild has no obligation to preserve. A public
identifier scheme had to be decided before the schema was, since every table needs one from its
first migration.

## Options considered

**Expose the internal auto-increment integer directly.** The simplest possible option, and
rejected outright for the reasons above: it is guessable, enumerable, and permanently couples a
public contract to an internal implementation detail this project already reserves the right to
change (partition maintenance, a future rebuild).

**Random UUIDv4.** Unguessable and already a de facto standard for opaque public ids. Rejected as
the *only* property considered: a v4 UUID carries no time ordering, so an insert into
`delivery_attempts` — an append-only, high-volume, monthly-partitioned table — lands at a random
point in whatever index is built over the public id, rather than at the end of it the way the
table's own insertion order already does. A cursor-paginated ledger (Ledger, Step 14) built on top
of an unordered public id would need a second, hidden ordering column just to make "newest first"
mean anything.

**ULID (Universally Unique Lexicographically Sortable Identifier), prefixed per resource type.**
Chosen. 128 bits of randomness, the same unguessable size as a UUID, but the first 48 bits are a
millisecond timestamp — so ids sort by creation time without a second column — encoded in
Crockford's base32, which is URL-safe, case-insensitive, and shorter than a hyphenated UUID.

## Decision

Every public identifier is a prefixed ULID: `app_`, `ep_`, `msg_`, `dlv_`, `att_`, `rpl_`,
generated once at creation by `HasPublicId` and never regenerated or reused. Internal integer
primary keys are never serialized by a Resource and never accepted as a route parameter — every
route model binding resolves through the public id, scoped by the tenant boundary (0001) the same
way every other query is. The prefix lets a caller's id be recognized, and a mismatched one
rejected, by shape alone, before a database round trip ever runs.

This is a different decision from the API key's own identifier, `pbk_<tenant ULID>_<secret>`,
which deliberately *does* encode the tenant — authentication needs to resolve which tenant a
request is for before it can touch the credential table at all, a problem no other public id in
this system has, since every other lookup already runs inside an established tenant context.

## Consequences

A public id sorts by creation time, which is free ordering for exactly the append-only, high-volume
tables that need it, and carries no information about *which* tenant created it, unlike the API
key's own scheme — the two identifier problems are solved differently on purpose, not
inconsistently. What this makes easy: a cursor built from `(created_at, public_id)` never needs a
second monotonic column, because the id itself is already one. What it forecloses: a public id can
never be used to infer total row count the way a sequential integer could, and repartitioning or
migrating the underlying table changes nothing a caller can observe.

What would falsify this decision: `ContractCoverageTest`'s assertion that the generated OpenAPI
document never describes an internal integer id on any response — the property this scheme
promises reviewers directly, held to the same contract every other guarantee in this project is
checked against, not merely asserted in prose.
