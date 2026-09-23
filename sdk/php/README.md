# PostBox PHP client SDK

Sends events to PostBox, verifies webhook signatures, and raises typed errors. Driven by the
same shared conformance vectors (`../../contract/signature-vectors.json`) as the TypeScript SDK
and the backend's own `App\Support\Delivery\Signature`, so all three agree byte for byte.

## Install

```bash
composer require postbox/sdk
```

You also need a [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and a
[PSR-17](https://www.php-fig.org/psr/psr-17/) request/stream factory — this package depends on
the interfaces only, never on a concrete client, so bring whichever implementation your
application already uses (Guzzle, Symfony HTTP Client, …).

## Publish an event

```php
use PostBox\PostBox;

$postBox = new PostBox(
    apiKey: $_ENV['POSTBOX_API_KEY'],   // pbk_...
    baseUrl: 'https://your-postbox-host/api',
    httpClient: $httpClient,            // Psr\Http\Client\ClientInterface
    requestFactory: $requestFactory,    // Psr\Http\Message\RequestFactoryInterface
    streamFactory: $streamFactory,      // Psr\Http\Message\StreamFactoryInterface
);

$message = $postBox->publish(
    applicationId: 'app_01...',
    eventType: 'invoice.paid',
    payload: ['invoice_id' => 'inv_123', 'total' => 4200],
);

echo $message->id;       // msg_01...
echo $message->replayed; // false on a fresh publish, true on an Idempotency-Key replay
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

```php
use PostBox\Webhook;

$webhook = new Webhook;

$valid = $webhook->verify(
    payload: $rawRequestBody,                          // the exact bytes received, unparsed
    signatureHeader: $request->header('PostBox-Signature'),
    timestampHeader: $request->header('PostBox-Timestamp'),
    secrets: ['whsec_...'],                             // every currently active secret
);
```

Pass every active secret during rotation — PostBox signs with all of them, and `verify()`
accepts a match against any one. A malformed or missing `PostBox-Timestamp` header returns
`false` rather than throwing.

## Errors

Every failure response throws a subclass of `PostBox\Exception\PostBoxException`, which carries
the HTTP `status` and response `headers`:

| Status | Exception | Notes |
|---|---|---|
| 401 | `AuthenticationFailed` | Missing, malformed, revoked, expired or wrong-tenant key. |
| 402 | `QuotaExhausted` | Cumulative quota for the billing period is spent. |
| 404 | `NotFound` | No such application for this tenant. |
| 409 | `IdempotencyConflict` | The key was already used for a different body. |
| 422 | `ValidationFailed` | Also carries `$errors` (field ⇒ messages). |
| 429 | `RateLimited` | Also carries `$retryAfter`; `publish()` already retried this itself. |
| 5xx | `ServerError` | `publish()` already retried this itself. |
| — | `TransportFailed` | The request never reached a response at all. |
