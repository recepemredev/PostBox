# Architecture Decision Records

This directory is written for a reader who has ten minutes and wants to know whether the
decisions in this codebase were made or merely defaulted into.

It is deliberately short. The full internal working record lives in
`.claude/docs/decision-log.md`; only decisions worth a reviewer's attention are promoted here.

## Index

| # | Title | Status | Written in |
|---|---|---|---|
| 0001 | Tenant isolation: single database with Row Level Security | planned | Step 2 |
| 0002 | Delivery guarantee: at-least-once via a transactional outbox | planned | Step 4 |
| 0003 | Retry schedule and injected jitter | planned | Step 7 |
| 0004 | Per-endpoint circuit breaker | planned | Step 8 |
| 0005 | Public identifiers: prefixed ULIDs | planned | Step 3 |
| 0006 | Why the repository pattern was rejected | planned | Step 17 |
| 0007 | Benchmark network resolves the SSRF-guard conflict without an allowlist | accepted | Step 10 |
| 0008 | OpenAPI contract generated from code, never hand-annotated | accepted | Step 12 |

An ADR is written in the step that implements the decision, not retroactively at the end. Step 17
only curates and cross-links them.

## Template

```markdown
# NNNN — Title

- **Status.** Proposed | Accepted | Superseded by NNNN
- **Date.** YYYY-MM-DD

## Context

The forces at play. What made this a decision rather than a default. Include the constraint that
actually mattered — traffic shape, failure mode, operational cost.

## Options considered

Each option with its real trade-off, not a strawman. An ADR whose alternatives are obviously bad
is not an ADR.

## Decision

What was chosen, stated in one sentence.

## Consequences

What this makes easy, what it makes hard, and what has to be true for it to keep holding. Name
the test or the measurement that would show the decision failing.
```

## Rules

- An ADR is superseded, never edited into a different decision. History is the value.
- Every ADR names the test or measurement that would falsify it.
- No ADR restates framework documentation. If the answer is "because that is how Laravel does
  it", there was no decision to record.
