# 0003 — Retry schedule and injected jitter

- **Status.** Accepted
- **Date.** 2026-09-12

## Context

A retry schedule has to satisfy two audiences that usually pull in opposite directions. In
production, many deliveries can start failing at the same instant — an endpoint goes down, every
delivery in flight to it fails together — and a schedule with no randomness at all makes every one
of them retry in lockstep, hitting the endpoint with a synchronized burst at every backoff
boundary, worse than the outage it is recovering from. In a test, the opposite property matters:
the exact sequence of delays has to be reproducible, or a test can only assert a range and never
actually pin the arithmetic.

## Options considered

**Fixed exponential backoff, no randomness.** Fully deterministic and simple to test, but exactly
the thundering-herd problem above: every delivery that failed at the same moment retries at the
same moment, forever, since nothing ever desynchronizes them.

**Full jitter** (`delay = random(0, min(ceiling, base·factorⁿ⁻¹))`). The strongest spread against a
thundering herd — the whole delay is random, up to the exponential cap. Rejected as this project's
default: a test can no longer assert an exact sequence at all, only that a delay fell inside a
range, which is a much weaker property than CLAUDE.md's "reproducible in tests" asks for; and nothing
stops an unlucky delivery from being handed a near-zero delay on consecutive attempts.

**Equal jitter** (half the delay fixed, half spread): `temp = min(ceiling, base·factorⁿ⁻¹)`, then
`delay = temp/2 + jitter()·temp/2`. Chosen. Still spreads a herd of simultaneous failures across a
real window, while the fixed half puts a floor under every delay and — critically — the whole
expression is a pure function of `n` and one call to an injected `jitter()`, so pinning that one
input reproduces the exact sequence on paper and in a test.

## Decision

`RetryPolicy` is a single object holding all five numbers that define the schedule — attempt
count, base delay, growth factor, jitter, ceiling (`config/postbox.php`'s `retry` block, and
nowhere else) — together with the one classification the schedule depends on: whether a given
failure is worth retrying at all. The two questions are deliberately not split into separate
classes: a caller that could ask "when" without also asking "should I" could retry something
terminal, and a caller that asked "should I" without "when" could hand a terminal failure a delay
nobody will ever wait out.

Retryable: every transport-level failure (timeout, DNS, TLS, connection refused), PostBox's own
`Blocked` refusal (no active secret, a disallowed address — both things an operator can fix while
the delivery is still trying), and a response of 408, 429, or any 5xx. Terminal: any other non-2xx,
which the endpoint produced deliberately and would produce identically on a retry.

Jitter comes from an injected `JitterSource` — the second of the two pre-approved boundary
abstractions (`RandomJitter` in production, a pinned fake in tests) — rather than a bare call to
PHP's random functions inside the policy. The clock needed no equivalent interface: `now` is
already a plain parameter threaded down from the job that calls `RetryPolicy::decide()`, and a
parameter is sufficient wherever a value only needs to vary *between* calls, not *within* one
deterministic expression the way a jitter source's random draw does.

## Consequences

The full schedule is defined in one place and changing any of its five numbers touches one config
block, never application code. `RetryScheduleTest` asserts the exact delay sequence with jitter
pinned at its two extremes (0 and 1) and its midpoint (0.5) — the property this decision has to
keep holding, and the test that would show it failing: if a future change to `RetryPolicy` makes
the sequence depend on anything the test does not already control (wall-clock time, a global RNG
seed), that test starts flaking instead of passing deterministically, which is the signal the
guarantee has quietly stopped being true.

The cost of folding retryable/terminal into the same object as the delay math is that `RetryPolicy`
now owns two concerns rather than one narrowly-scoped one — accepted deliberately, since splitting
them is exactly the failure mode this decision exists to prevent, not an unrelated simplification
left on the table.
