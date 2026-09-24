# 0004 — Per-endpoint circuit breaker

- **Status.** Accepted
- **Date.** 2026-09-12

## Context

A consistently failing endpoint should not go on consuming a worker's time one request at a time —
every attempt against a fully down endpoint still pays a connect timeout, still gets recorded, and
still occupies a slot that a healthy endpoint's own delivery could have used. The schedule already
built for retries (0003) governs one delivery's own attempts; it has no opinion about an endpoint
as a whole, and nothing stops a hundred deliveries queued against the same broken endpoint from
each independently discovering, one connect-timeout at a time, that it is down. The harder half of
the problem is re-enabling: an endpoint that comes back has to be found out safely, without the
entire backlog that built up while it was down landing on it in the same instant it recovers.

## Options considered

**A failure counter and a manual disable switch.** The cheapest mechanism — an operator flips an
endpoint off once notified, and back on once it looks fixed. Rejected: it depends on a human
noticing and acting, which is neither automatic nor bounded in time, and CLAUDE.md's own rule — "an
endpoint is re-enabled only through the half-open probe path" — presupposes a path that exists
without a person driving it.

**A two-state breaker (closed/open) that reopens automatically after a fixed timeout.** Automatic,
and simple to reason about — but reopening means resuming full traffic the instant the timer
expires, with no verification that anything actually changed. Every deferred delivery queued
against the endpoint arrives at once, which is worse than the failure pattern the breaker exists to
protect against: instead of one worker discovering a timeout at a time, every worker discovers a
fresh failure at once, on an endpoint that may not even be back yet.

**A three-state breaker (closed/open/half-open) admitting exactly one probe.** Chosen. Recovery is
automatic, but gated: only a single probe's own outcome decides whether to resume full traffic or
trip again, so the backlog never learns anything about the endpoint's health except through that
one request.

## Decision

`BreakerState` owns the entire transition table in one place — `canTransitionTo()` — closed→open,
open→half-open, half-open→{closed, open}, and nothing else; every other move is rejected before a
row is touched. `TransitionBreaker` is the only class that ever writes a state change, and it
writes the audit entry in the same call — the two always happen together, never one without the
other. A write is a conditional `UPDATE … WHERE state = $observed`, so two callers proposing the
same move — two failing attempts trying to open a breaker at once, two dispatcher passes both
attempting to admit a probe — have exactly one winner; the loser is told it lost, not told its
request was illegal, because losing a race and asking for an illegal move are different defects.

A breaker row is created lazily, the same shape `quota_usage` already takes: no row exists until an
endpoint has tripped at least once, so a healthy endpoint — the overwhelming majority — costs
nothing. `RecordAttemptOutcome` decides whether a just-recorded attempt should trip or close the
breaker, reading the trailing failure window straight from `delivery_attempts` rather than keeping
a second counter in step with it. This is deliberately a different question from `RetryPolicy`'s
own retryable/terminal split: every non-success counts toward the breaker the same way, because "is
this endpoint healthy" and "is this particular response worth trying again" are not the same
question — a terminal 404 answers the second one and still means exactly what a 500 does to the
first. `AdmitEndpoint` is asked once per endpoint, for an entire batch the dispatcher has already
claimed — never per delivery, and never again once a job reaches a worker — so an open breaker
stops traffic before a worker ever boots, not as a check one pays for inside it. A half-open
breaker admits exactly one probe from a claimed batch; everything else in that batch is deferred to
the probe's own timeout rather than to whenever the probe happens to resolve — a deliberately
conservative choice, recorded rather than left implicit.

## Consequences

An endpoint is re-enabled through exactly one path, ever, and nothing else can close a breaker —
not a stray success that reaches a worker while the breaker is technically still open (it is
treated as having started before the trip and given no authority to end it), only a probe that was
actually admitted through the half-open state. A deferred delivery is untouched, not
re-recorded: no attempt is written, `attempt_count` does not move, and the row stays exactly as
pending as it was, which is what "a message queued while open is not lost" means concretely.

What would falsify this decision: `BreakerTransitionTest` (every legal transition lands a row and
an audit entry together; every illegal one — including a state transitioning to itself — is
rejected before either is touched) and `BreakerRaceTest` (a genuine two-session race on an
endpoint's first-ever trip, and on every later compare-and-set move, has exactly one winner, proven
against a real rival session rather than asserted from the code's own shape). The conservative
choice to defer an entire batch to the probe's own timeout is itself named as a cost in D68 rather
than hidden — the alternative (releasing the batch the moment the probe concludes) was available
and rejected as a second signal duplicating one the next scheduled pass already reads for free.
