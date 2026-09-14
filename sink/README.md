# Load sink

A Laravel Octane receiver with configurable `delay`, `fail_rate` and `status`, used as the
target for the k6 load runs (Step 10). It is a test double that happens to run in a
container: the delivery engine never depends on it (`modules.md`).

`delay`, `fail_rate` and `status` are read from the query string of the delivered URL only —
never from the request body, since a benchmark delivers tenant payloads the sink does not
control. A scheduled failure is deterministic rather than random: over any window of 100
consecutive requests, exactly `fail_rate * 100` of them fail, fixed by request sequence alone,
so a committed k6 script reproduces the same retry queue depth and breaker activations on every
run. See `App\Support\FailureSchedule`.

PostBox's own delivery-failure tests (retry classification, the circuit breaker, the dead
letter queue) double the transport boundary instead — `HttpTransport` behind a Mockery double,
or Guzzle's `MockHandler` — and do not send to the sink. That keeps them deterministic without
depending on a container being up, and is why the sink is Bench's only consumer (`modules.md`).

Runs on a benchmark-only Docker network (`compose.bench.yaml`) at an address `AddressGuard`
already allows — see decision D75/D84 in `.claude/docs/decision-log.md`. Never part of the
production profile; `docker/scripts/assert-bench-absent-from-production.sh` asserts it in CI.
