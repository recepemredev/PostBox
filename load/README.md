# Load harness

k6 scripts, SQL queries and recorded results behind every number in the root README's benchmark
table.

## Why the sink ceiling comes first

`sink/` (a Laravel Octane receiver, not part of the delivery engine) is the target of every load
run. A saturated sink would measure the sink instead of PostBox, so the first phase always finds
the sink's own ceiling, and every later PostBox figure is reported next to it. A figure at or above
80% of the sink ceiling is reported as sink-bound, never claimed as a PostBox throughput number.

## The phases

0. **Environment record.** Host, container limits, dependency versions and image digests —
   committed alongside the results that were measured under them.
1. **Sink ceiling.** `sink-ceiling.js` drives the sink directly, bypassing PostBox.
2. **Ingest throughput.** `ingest.js` drives `POST /v1/apps/{app}/messages` with delivery workers
   stopped — isolates validation, idempotency and the outbox write.
3. **End-to-end delivery.** Workers running against a clean sink; ingest-to-delivered latency.
4. **Scaling curve.** A fixed backlog drained at 1, 3 and 5 worker containers — not a repeat of
   Phase 3, since Phase 3's own ceiling is upstream of worker count entirely (FPM pool size, the
   outbox dispatcher's once-a-minute schedule).
5. **Degraded receiver.** Two endpoints, one clean and one failing on a fixed schedule, fanned out
   from the same publish — measures whether one endpoint's circuit breaker starves the other.

`queries/delivery-throughput.sql` reads Phases 3–5's own numbers back out of Postgres; run it as
`postbox_app` or the schema owner, never the superuser — Row Level Security does not bind a
superuser, so a tenant filter would silently span every tenant instead of refusing to.

## Results

`results/<date>/` holds the raw k6 JSON, the environment record and a short summary for each run;
`results/` itself is deliberately untracked, so only the scripts and the recorded environment are
committed — never a number without the script that produced it. The root README's table is the
curated, cross-run summary: phase, workers, throughput, p95, sink ceiling, date.

## What is deliberately not claimed

- No comparison against Svix, Hookdeck or any other product.
- No figure from a run whose script is not committed.
- No figure carried forward across a change to the delivery path.
- No single-number headline without the sink ceiling next to it.
