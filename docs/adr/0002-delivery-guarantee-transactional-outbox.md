# 0002 — Delivery guarantee: at-least-once via a transactional outbox

- **Status.** Accepted
- **Date.** 2026-09-11

## Context

Ingest has one job that cannot fail in either direction: accepting an event can never lose it, and
a retried request can never double it (idempotency is 0003's sibling concern; this decision is
about the first half). The obvious implementation — write the message row, then push a job onto
the queue — is a dual write across two different systems with no shared transaction, and the
window between them is exactly where a delivery goes missing: a crash, a deploy, or a queue
connection failure between the `INSERT` and the `dispatch()` call loses the message forever, with
nothing in Postgres or Redis left to say it should have gone out at all.

## Options considered

**Enqueue directly in the request path, after commit.** The conventional shape, and the one every
framework tutorial shows. Rejected: the crash window described above is real and unrecoverable
under this shape — there is no record of the missed delivery to notice, retry, or even alert on.

**Enqueue inside the same database transaction as the write.** Closes half the window — a
transaction that never commits never enqueues — but not the other half: the process can still die
in the gap between the commit succeeding and the enqueue call that was meant to follow it inside
the same request. It also could, if applied naively, hand a worker a queued job pointing at a row
that turns out not to exist should the transaction ever roll back after the enqueue call, which is
the reverse failure — a job with nothing behind it.

**Transactional outbox.** The message and its pending deliveries are written in one database
transaction — nothing is enqueued from the request path at all. A separate, scheduled dispatcher
reads the outbox afterward and hands due rows to the queue. Chosen.

## Decision

`PublishMessage` writes the message row and every subscribed endpoint's pending `deliveries` row
in one transaction; the HTTP response is only sent after that transaction commits. Enqueueing is a
different action (`DispatchOutbox`) run on a schedule (`outbox:dispatch`, every minute) rather than
triggered by the request that filled the outbox — the request path enqueueing anything at all is
precisely what this pattern exists to avoid. The dispatcher claims due rows with
`for update skip locked`, so two overlapping dispatcher passes take disjoint sets rather than
racing each other, and pushes `next_attempt_at` forward by a lease before handing a row to a
worker — a delivery whose worker never actually ran (a crashed process, a lost job) becomes due
again once the lease expires, rather than being enqueued and then silently abandoned. A worker then
claims a delivery, sends it, and records the attempt — success or failure — before deciding the
row's next state.

## Consequences

A crash anywhere between the transaction committing and a worker's attempt costs latency, never
data: worst case, a message waits up to the dispatcher's own interval for its first attempt, and a
lost job waits up to one lease for a second. Nothing about this guarantee depends on the queue
being reliable — the outbox row is the durable record, the queue is only ever a hint that speeds a
row's own passage through it up.

The guarantee is at-least-once, not exactly-once, and that is a stated trade rather than an
oversight: a worker that sends successfully and then crashes before recording the attempt will have
the same delivery sent again on the next pass. Duplicates are possible by design; the signature
header carries the message's own public id precisely so a receiving consumer can deduplicate on it,
and this is documented in the public API, not hidden as an edge case.

What would falsify this decision: `IdempotentPublishTest`'s crash-window case (a transaction that
never commits produces no delivery at all) and `OutboxClaimRaceTest` (two dispatcher passes running
concurrently claim disjoint sets, and a claim that itself rolls back gives its rows back
immediately rather than after the lease expires). If either ever shows a message committed but
never delivered, or delivered because of the request path rather than the schedule, this decision
has stopped holding.
