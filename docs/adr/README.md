# Architecture Decision Records

This directory is written for a reader who has ten minutes and wants to know whether the
decisions in this codebase were made or merely defaulted into.

It is deliberately short. A fuller internal working record exists on the maintainer's own machine,
untracked by design (`CLAUDE.md`) — only decisions worth a reviewer's attention are promoted here.

## Index

| # | Title | Status | Written in |
|---|---|---|---|
| 0001 | Tenant isolation: single database with Row Level Security | accepted | Step 17 |
| 0002 | Delivery guarantee: at-least-once via a transactional outbox | accepted | Step 17 |
| 0003 | Retry schedule and injected jitter | accepted | Step 17 |
| 0004 | Per-endpoint circuit breaker | accepted | Step 17 |
| 0005 | Public identifiers: prefixed ULIDs | accepted | Step 17 |
| 0006 | Why the repository pattern was rejected | accepted | Step 17 |
| 0007 | Benchmark network resolves the SSRF-guard conflict without an allowlist | accepted | Step 10 |
| 0008 | OpenAPI contract generated from code, never hand-annotated | accepted | Step 12 |

An ADR is meant to be written in the step that implements the decision. 0001–0004 were not — each
was flagged in the decision log at the time (D3, D34, D60, D69) and left as a curation debt until
this step actually paid it off; 0005 and 0006 were never flagged with their own decision-log entry
at all, having stood as plain rules in CLAUDE.md and `architecture.md` since Step 1. Every ADR's
`Date` field is the decision's own date, not this step's; the `Written in` column says honestly
when the file was — a reviewer checking either should see the gap, not a record that quietly
closes over it.

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
