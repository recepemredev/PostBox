# PostBox

A self-hosted, multi-tenant webhook delivery gateway. Tenants register endpoints, publish events
through an API, and PostBox guarantees delivery with HMAC signing, idempotency, retries with
backoff, and per-endpoint circuit breaking.

> **Status: under construction.** Step 1 of 17 — foundation and quality gates. The application
> shell, container topology and CI gates exist; the delivery engine does not yet. See
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
