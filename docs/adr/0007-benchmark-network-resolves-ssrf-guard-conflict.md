# 0007 — Benchmark network resolves the SSRF-guard conflict without an allowlist

- **Status.** Accepted
- **Date.** 2026-09-14

## Context

The load sink (Step 10) is a container. On Docker's default network its address lands inside
`172.16.0.0/12`, one of `AddressGuard`'s disallowed ranges (private, loopback and link-local
addresses, refused on every delivery attempt, not only at endpoint registration). Every delivery
to the sink was refused before a byte left the process.

The constraint that made this a design decision rather than a configuration one: whatever
resolved it could not be reachable from the production profile. An allowlist entry that reaches
production is not a benchmarking convenience — it is the SSRF protection choosing its own
exception, which is the exact failure `AddressGuard` exists to prevent (a tenant endpoint pointed
at a cloud metadata address, or at another container on the same host).

## Options considered

**A configured allowlist in `AddressGuard`.** The most direct fix, and rejected outright: any
mechanism for naming an exception is a mechanism a misconfigured or compromised production
deployment could also use. The guard's disallowed ranges are a `private const`, deliberately not
`env()`-driven, so there is no config surface for an allowlist to extend even to a single line.

**Giving the sink a routable address.** Considered because it would need no benchmark-specific
network at all. Rejected as impractical: `host.docker.internal`, a published host port, and a
host LAN address all resolve into the same private ranges the sink's default-network address
already falls in. There was no address a local container could hold that this guard would not
already refuse — the option didn't survive contact with the actual disallowed-range list.

**A second, bench-only `AddressGuard` binding.** Rejected as a second delivery path — the same
reasoning an earlier decision (test-event delivery, D76) already applied: two rule sets for one
mechanism is the failure this project exists to demonstrate avoiding, not reproduce for
convenience.

## Decision

`compose.bench.yaml` defines a `bench` Docker network on `203.0.113.0/24` — TEST-NET-3 (RFC
5737), reserved for documentation and examples, routed nowhere on the public internet, and
already outside every range `AddressGuard` disallows. Only the load sink and the delivery workers
join it; PostgreSQL and Redis stay on the default network, where the guard still refuses them.
`AddressGuard` itself is unchanged — zero lines.

## Consequences

The property this decision has to keep holding is structural, not conditional: the `bench`
network exists only in `compose.bench.yaml`, a file never composed with `compose.prod.yaml`.
`docker/scripts/assert-bench-absent-from-production.sh` — run by both task runners' `ci` target
and the CI `images` job — asserts the resolved production configuration names neither the sink,
the network, nor its subnet, and that neither runner ever composes the two files together. That
script is what would show this decision failing: if it starts passing after someone changes the
production compose invocation, the guarantee is gone regardless of what `AddressGuard.php` still
says.

The other side of the same coin lives in `AddressGuardTest`: one case pins the address a container
was actually observed at on Docker's default network (`172.22.0.4`) into the disallowed dataset,
and another asserts the bench range is allowed — a standing guard against someone hardening the
range list with the RFC 5737 documentation ranges and silently taking the benchmark out of
service, since nothing else in the suite ever sends to the sink.
