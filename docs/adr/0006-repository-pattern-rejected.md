# 0006 — Why the repository pattern was rejected

- **Status.** Accepted
- **Date.** 2026-08-27

## Context

The repository pattern — an interface per aggregate, an implementation wrapping the ORM behind it
— is close to a reflex in applications that describe themselves as layered, and this project's own
layering (Controller → Form Request → Action → Model → Policy → Resource) invites the question of
whether a Repository belongs between Action and Model. The actual reason it would exist is to let
persistence be swapped or doubled independently of the business logic that uses it. This project
has exactly two places where that is a real, exercised need — the outbound HTTP transport and the
clock/jitter source, both pre-approved boundary interfaces precisely because delivery timing has to
be deterministic under test — and zero places where the ORM itself is the seam a test needs to
vary.

## Options considered

**A repository interface and implementation per model.** The standard shape, and the one most
often defended on decoupling grounds. Rejected: nothing in this codebase ever substitutes a
different persistence backend for Eloquent + PostgreSQL — it is a permanent architectural choice,
not a variable under test — so a repository wrapping it one-to-one would be a second name for the
same call, an interface with exactly one implementation that will only ever have one, forever.
CLAUDE.md makes the rejection explicit rather than leaving the omission to look like an oversight:
"Repository pattern is deliberately NOT used. Eloquent models are used directly."

**A single generic base repository, composed or extended by every model.** Considered as a way to
avoid a file per model. Rejected as the same mistake in a more speculative shape: a generic,
config-driven wrapper is precisely the kind of abstraction CLAUDE.md's third-use-case rule exists
to stop — built ahead of the second real use case it would serve, let alone the third — and every
one of its methods would still be a one-line passthrough to the Eloquent call already sitting
behind it, hiding nothing a caller could not already see.

**Business logic in Action classes, models kept to persistence shape only.** Chosen. A query is
written directly against Eloquent inside the Action that needs it, tenant-scoped automatically by
the model's own global scope (0001); the Model layer holds relations, casts, and query scopes, and
no business logic of its own.

## Decision

No repository layer exists anywhere in this codebase. An Eloquent model is the data layer, used
directly. The only two abstractions placed in front of infrastructure remain the ones CLAUDE.md
names as pre-approved exceptions to the third-use-case rule — outbound HTTP transport and the
clock/jitter source — both justified by an actual, exercised need to swap the real implementation
for a deterministic one under test. A Model query has no equivalent need: the database is not
swapped in a test, it is the same PostgreSQL instance the application always runs against, wrapped
in a transaction `RefreshDatabase` rolls back.

## Consequences

What this makes easy: reading a query exactly where the business logic that needs it lives, with
Eloquent's own tenant-scoping, casts, and relationships doing real work rather than being
reimplemented behind an interface that would otherwise need to reproduce all three. An Action is
directly testable against a real, migrated test database — the same one CI and production run
against, just scoped to a transaction — without a stand-in that could quietly drift from what
Eloquent actually does.

What this makes hard, and deliberately so: swapping the ORM or the underlying database. Accepted,
because this project has never needed to and nothing in its roadmap plans to — the two boundary
interfaces that do exist were built for a documented, exercised reason (deterministic delivery
timing under test), not spread speculatively to a place with no comparable need.

What would falsify this decision, and the test that would show it: a second, genuinely different
persistence backend actually becoming necessary — a read model that has to live outside PostgreSQL,
for instance. The day an Action needs to run against two different implementations of the same
query is the day a repository interface finally has a second implementation to justify existing,
which is exactly the third-use-case bar this document holds every other abstraction in the codebase
to, applied to itself.
