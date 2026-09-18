# 0008 — OpenAPI contract generated from code, never hand-annotated

- **Status.** Accepted
- **Date.** 2026-09-18

## Context

The frontend and, later, the TypeScript SDK need typed knowledge of the HTTP API: eleven routes,
two credential schemes assigned per route rather than globally, six rate-limit and quota headers,
an idempotent-replay status pair, and four error responses thrown from middleware rather than a
controller's own code. `conventions.md` already committed the frontend to "API types are generated
from the OpenAPI contract. Hand-written response types are not allowed" before this step existed —
the open question was how the contract itself gets written, and how it is kept from drifting the
moment a route changes and nobody remembers to update it.

## Options considered

**Hand-written OpenAPI YAML or JSON.** The most direct option, and the one every "drift" risk in
this decision exists to avoid: a second, manually maintained description of an API whose real
shape lives in the routes, Form Requests and Resources. Every one of this project's own priority
guarantees — tenant isolation, idempotency, signature verification — is enforced by code and
proven by a test that can be made to fail; a hand-written spec has neither property. It would pass
review the day it was written and silently stop matching reality the first time it wasn't updated.

**PHP attribute annotations on every controller (`zircote/swagger-php` and similar).** Closer to
"generated" in spirit — the spec is built from the code at generation time — but the annotations
themselves are a second description sitting beside the code they describe, not derived from it:
`@OA\Response(response=200, ...)` still has to be kept in step with the controller by hand, the
same failure mode as hand-written YAML, one layer removed. It is also the literal thing `app/`
already has zero of (`grep -rn "#\[" app/` returns nothing) and D9's "no annotations to drift"
reasoning already ruled out in spirit, well before this step needed a concrete answer.

**`dedoc/scramble`.** Builds the document by statically analyzing the routes, Form Request rules,
Resource `toArray()` bodies and PHPStan-style array-shape docblocks that already exist for their
own reasons — Larastan reads the same shapes. Nothing under `app/` is written for Scramble's
benefit specifically. Its own inference has real gaps — most visibly, a response built by
`->response($request)->setStatusCode(...)` rather than returned as a bare Resource, and an array
spread conditioned on a runtime value (`ReplayResource`, D83) — but every gap is a structural
limit of static analysis on real code, not a maintenance burden invented to make the tool work.

## Decision

`dedoc/scramble` generates `contract/openapi.json` from the application as it already exists. What
its static analysis cannot see — assigning one of two credential schemes per route by middleware,
six Governor headers, the idempotent-replay status pair, `Idempotency-Key` arriving as a header but
validated as a body field, and four exceptions thrown outside the controller's own call graph — is
supplied by five small classes under `App\Support\Contract`, each implementing one of Scramble's
own extension points (`DocumentTransformer` or `OperationTransformer`), never a controller
attribute. The generated document and the frontend's generated TypeScript types
(`frontend/src/types/api.d.ts`) are both committed, and `ci` fails if regenerating either produces
a diff.

## Consequences

An endpoint added, an error response changed, or a header removed from `EnforceLimits` without
regenerating the contract fails the build — on either side, since the backend CI job diffs the
regenerated document against what is committed and the frontend job independently diffs the
regenerated types. `ContractCoverageTest`'s own drift assertion (`generatedContract()` compared to
`committedContract()`) is the test that would show this decision failing locally, and it was
sabotaged and reverted to confirm it: adding an undocumented route left route-coverage passing (it
reads the live route table, by design) but broke the drift assertion, which is the one an actual
CI run depends on.

What this makes easy: the contract can never describe a credential scheme, a header or a status
code the running application does not actually produce, because it is read from the same code that
produces them. What it makes hard: the two structural gaps named above needed hand-built schemas
rather than a single annotation — a real, bounded cost, paid once per gap rather than once per
route.
