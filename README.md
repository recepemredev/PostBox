# PostBox

A self-hosted, multi-tenant webhook delivery gateway. Tenants register endpoints, publish events
through an API, and PostBox guarantees delivery with HMAC signing, idempotency, retries with
backoff, and per-endpoint circuit breaking.

> **Status: under construction.** Step 11 of 17 — horizontal scaling & concurrency safety.
> Tenancy, identity, the domain model, ingest, Governor, the delivery engine and resilience are
> done; the concurrency guarantees the delivery path relies on are now proven under genuine
> two-session tests, and the full benchmark protocol (Phases 0–5) exists below. See
> `.claude/docs/roadmap.md`.

## Quickstart

Requires Docker and Docker Compose. Nothing else — PHP, Composer and Node run inside containers.

```powershell
.\task.ps1 up      # Windows
```

```bash
make up            # Linux / macOS
```

Then:

| URL | What |
|---|---|
| http://localhost:8080 | Dashboard (Next.js, through nginx) |
| http://localhost:8080/api/health | Deep health check — database, Redis, queue, migrations |
| http://localhost:8025 | Mailpit (development profile only) |

`task.ps1 down` stops the stack; `task.ps1 fresh` rebuilds it from empty volumes.

## Benchmark

The first honest number, per `.claude/docs/benchmarking.md`: a sink ceiling reported next to
every PostBox figure, and no claim without a committed k6 script behind it. Full results,
environment record and raw k6 output: `load/results/2026-09-14/` (Phases 0–3),
`load/results/2026-09-17/` (Phases 4–5).

| Phase | Workers | Throughput | p95 | Sink ceiling | Date |
|---|---|---|---|---|---|
| 1 — Sink ceiling | — | ≥ 6,400 req/s | 1.78ms | — | 2026-09-14 |
| 2 — Ingest (workers stopped) | 0 | 19.9 req/s | 276ms | ≥ 6,400 req/s | 2026-09-14 |
| 3 — End-to-end delivery | 1 | 14.9 req/s ingest · 100% delivered | 257ms ingest · 44s ingest-to-delivered | ≥ 6,400 req/s | 2026-09-14 |
| 4 — Backlog drain | 1 container (10 processes) | 400.0 deliveries/s | — | ≥ 6,400 req/s | 2026-09-17 |
| 4 — Backlog drain | 3 containers (30 processes) | 500.0 deliveries/s | — | ≥ 6,400 req/s | 2026-09-17 |
| 4 — Backlog drain | 5 containers (50 processes) | 1,000.0 deliveries/s | — | ≥ 6,400 req/s | 2026-09-17 |
| 5 — Degraded receiver | 1 container | 100% delivered (clean) · 0 dead-lettered (degraded) | — | ≥ 6,400 req/s | 2026-09-17 |

Phase 2's ceiling is `pm.max_children = 5` — the stock PHP-FPM pool size, never tuned by this
repository — not the ingest logic itself. Phase 3's 44-second delivery latency is
`DispatchOutboxCommand`'s once-a-minute schedule, not worker throughput: every attempt that ran
succeeded in about 1ms.

**Phase 4's curve is not linear, and not the way diminishing returns would predict**: 1→3
containers gains 25%, 3→5 then gains 100%. Per-attempt latency rises with concurrency (1ms → 3ms
→ 4ms, `avg_duration_ms` — millisecond precision), consistent with shared-resource contention
(Postgres row locks, connection pool) rather than the sink or the network; aggregate throughput
still climbs, so the system is not saturated at 5 containers. The exact multipliers carry real
measurement noise — `deliveries.last_attempted_at` is whole-second precision, and this backlog
drains in single-digit seconds — named rather than hidden in `load/results/2026-09-17/README.md`.

**Phase 5's clean endpoint was not starved by its degraded sibling — structurally, not by luck**:
admission is asked once per endpoint before a delivery ever reaches a worker, and a deferred
delivery never touches the retry queue, which stayed at 0 throughout the run.

## No server exists

PostBox is not deployed anywhere. Production readiness is verified locally: the production
profile builds, starts and passes the same deep health check (`task.ps1 prod-up`). This README
will not imply a deployment that does not exist — see `.claude/docs/deployment.md`.

## Documentation

| Document | What |
|---|---|
| `CLAUDE.md` | Operating contract and critical business rules |
| `.claude/docs/architecture.md` | Layers, tenancy, delivery guarantee |
| `.claude/docs/roadmap.md` | The 17 steps and their definition of done |
| `docs/adr/` | Public Architecture Decision Records |
