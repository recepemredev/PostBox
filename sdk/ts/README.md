# PostBox TypeScript client SDK

Sends events to PostBox, verifies webhook signatures, and raises typed errors. Driven by the
same shared conformance vectors (`../../contract/signature-vectors.json`) as the PHP SDK and the
backend's own `App\Support\Delivery\Signature`, so all three agree byte for byte.

`Message`'s wire shape is sourced from the generated `src/types/api.d.ts`
(`npm run contract:types`, from `../../contract/openapi.json`), never hand-typed — the same rule
`frontend/` follows.

## Install

```bash
npm install @postbox/sdk
```

Node 18+ (native `fetch`, `node:crypto`). No runtime dependencies.

## Publish an event

```ts
import { PostBox } from "@postbox/sdk";

const postBox = new PostBox({
  apiKey: process.env.POSTBOX_API_KEY!,   // pbk_...
  baseUrl: "https://your-postbox-host/api",
});

const message = await postBox.publish({
  applicationId: "app_01...",
  eventType: "invoice.paid",
  payload: { invoiceId: "inv_123", total: 4200 },
});

console.log(message.id);       // msg_01...
console.log(message.replayed); // false on a fresh publish, true on an Idempotency-Key replay
```

### Idempotency and retries

`publish()` accepts an `idempotencyKey`; when none is given, it generates one and reuses it
across every retry attempt of that same call, so a network error or a 5xx never risks a second
message for the same event. It retries a transport failure, a 5xx, or a 429 — honouring the
server's own `Retry-After` when it gives one — up to `RetryPolicy`'s schedule (3 attempts, 500ms
base delay, doubling, 8s ceiling). A 401, 402, 404, 409 or 422 is never retried.

**A replayed request still spends rate limit and quota** — PostBox's governor runs before the
idempotency check, so even a request that turns out to be a pure replay costs one token and one
quota unit. Retrying is safe for correctness, not free.

## Verify a webhook

```ts
import { Webhook } from "@postbox/sdk";

const webhook = new Webhook();

const valid = webhook.verify(
  rawRequestBody,                          // the exact bytes received, unparsed
  request.headers.get("PostBox-Signature")!,
  request.headers.get("PostBox-Timestamp")!,
  ["whsec_..."],                           // every currently active secret
);
```

Pass every active secret during rotation — PostBox signs with all of them, and `verify()`
accepts a match against any one. A malformed or missing `PostBox-Timestamp` header returns
`false` rather than throwing.

## Errors

Every failure response throws a subclass of `PostBoxError`, which carries the HTTP `status` and
response `headers`:

| Status | Error | Notes |
|---|---|---|
| 401 | `AuthenticationFailedError` | Missing, malformed, revoked, expired or wrong-tenant key. |
| 402 | `QuotaExhaustedError` | Cumulative quota for the billing period is spent. |
| 404 | `NotFoundError` | No such application for this tenant. |
| 409 | `IdempotencyConflictError` | The key was already used for a different body. |
| 422 | `ValidationFailedError` | Also carries `errors` (field ⇒ messages). |
| 429 | `RateLimitedError` | Also carries `retryAfter`; `publish()` already retried this itself. |
| 5xx | `ServerError` | `publish()` already retried this itself. |
| — | `TransportFailedError` | The request never reached a response at all. |
