/**
 * Every error this SDK throws carries the HTTP status PostBox answered with
 * (0 for a transport-level failure that never reached a response) and the
 * response headers, so a caller can inspect either without parsing the
 * message string. One subclass per status the ingest endpoint is documented
 * to return — mirrors sdk/php's PostBox\Exception hierarchy class for class.
 */
export abstract class PostBoxError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly headers: Record<string, string> = {},
  ) {
    super(message);
    this.name = new.target.name;
  }
}

/**
 * 401: the API key is missing, malformed, revoked, expired or belongs to
 * another tenant. PostBox answers all five the same way on purpose, so this
 * error carries whatever message it was given rather than guessing why.
 */
export class AuthenticationFailedError extends PostBoxError {
  constructor(message: string, headers: Record<string, string> = {}) {
    super(message, 401, headers);
  }
}

/**
 * 402: this tenant's cumulative quota for the current billing period is
 * exhausted. Never retryable within a run — the period resets on a clock,
 * not on a delay this SDK could wait out.
 */
export class QuotaExhaustedError extends PostBoxError {
  constructor(message: string, headers: Record<string, string> = {}) {
    super(message, 402, headers);
  }
}

/**
 * 404: no such application for this tenant. Retrying with the same
 * application id would only repeat the same 404.
 */
export class NotFoundError extends PostBoxError {
  constructor(message: string, headers: Record<string, string> = {}) {
    super(message, 404, headers);
  }
}

/**
 * 409: this Idempotency-Key was already used for a request with a different
 * body. Reached only when a caller reuses a key by hand across two distinct
 * publish() calls — PostBox.publish() never repeats one across attempts
 * with a different body, since the body is fixed before the first attempt.
 */
export class IdempotencyConflictError extends PostBoxError {
  constructor(message: string, headers: Record<string, string> = {}) {
    super(message, 409, headers);
  }
}

/**
 * 422: eventType is not registered for this tenant, payload is missing or
 * empty, payload exceeds the size ceiling, or the Idempotency-Key header
 * carries a control character. errors mirrors the field => messages shape
 * Laravel's validator renders.
 */
export class ValidationFailedError extends PostBoxError {
  constructor(
    message: string,
    public readonly errors: Record<string, string[]>,
    headers: Record<string, string> = {},
  ) {
    super(message, 422, headers);
  }
}

/**
 * 429: the token bucket for this tenant is empty. PostBox.publish() retries
 * this one itself, honouring Retry-After — it only reaches a caller once
 * every retry attempt has also been rate limited.
 */
export class RateLimitedError extends PostBoxError {
  constructor(
    message: string,
    public readonly retryAfter: number | null,
    headers: Record<string, string> = {},
  ) {
    super(message, 429, headers);
  }
}

/**
 * 5xx: PostBox itself failed to process the request. publish() retries this
 * one; it only reaches a caller once every retry attempt has also failed.
 */
export class ServerError extends PostBoxError {
  constructor(message: string, status: number, headers: Record<string, string> = {}) {
    super(message, status, headers);
  }
}

/**
 * The request never produced a response at all — DNS, TLS, a connection
 * refused, a timeout inside fetch. Status is 0: there was no HTTP status to
 * carry. publish() retries this one; it only reaches a caller once every
 * retry attempt has also failed to reach the server.
 */
export class TransportFailedError extends PostBoxError {
  constructor(
    message: string,
    public readonly cause?: unknown,
  ) {
    super(message, 0, {});
  }
}

/**
 * A status the ingest endpoint's contract does not document. Never retried:
 * an unrecognised status is treated the same as a terminal one, on the
 * principle that a status this SDK cannot classify is not one it can decide
 * is safe to repeat.
 */
export class UnexpectedResponseError extends PostBoxError {
  constructor(message: string, status: number, headers: Record<string, string> = {}) {
    super(message, status, headers);
  }
}
