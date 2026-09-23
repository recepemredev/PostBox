import { randomBytes } from "node:crypto";
import {
  AuthenticationFailedError,
  IdempotencyConflictError,
  NotFoundError,
  type PostBoxError,
  QuotaExhaustedError,
  RateLimitedError,
  ServerError,
  TransportFailedError,
  UnexpectedResponseError,
  ValidationFailedError,
} from "./errors.js";
import { type Message, messageFromResponseBody } from "./Message.js";
import { RetryPolicy } from "./RetryPolicy.js";

type Fetch = typeof fetch;

export interface PostBoxOptions {
  apiKey: string;
  baseUrl: string;
  fetch?: Fetch;
  retryPolicy?: RetryPolicy;
  sleep?: (milliseconds: number) => Promise<void>;
}

export interface PublishOptions {
  applicationId: string;
  eventType: string;
  /** A non-empty JSON object or array. */
  payload: Record<string, unknown> | unknown[];
  idempotencyKey?: string;
}

/**
 * Publishes events to PostBox's ingest endpoint:
 * `POST {baseUrl}/v1/apps/{applicationId}/messages`, `Authorization: Bearer
 * {apiKey}`. Mirrors sdk/php's PostBox class method for method; `fetch` is
 * this package's PSR-18 boundary equivalent — injected so a caller (or a
 * test) can supply its own, rather than this package picking an HTTP client.
 *
 * A request that failed for a reason the same Idempotency-Key makes safe to
 * repeat — a transport failure, a 5xx, or a 429 (honouring Retry-After when
 * the server gives one) — is retried, up to RetryPolicy's own schedule, with
 * that same key. A 401/402/404/409/422 is never retried: retrying would
 * either never fix it or, for 409, actively misuse the key.
 */
export class PostBox {
  private readonly apiKey: string;
  private readonly baseUrl: string;
  private readonly fetchFn: Fetch;
  private readonly retryPolicy: RetryPolicy;
  private readonly sleepFn: (milliseconds: number) => Promise<void>;

  constructor(options: PostBoxOptions) {
    this.apiKey = options.apiKey;
    this.baseUrl = options.baseUrl;
    this.fetchFn = options.fetch ?? fetch;
    this.retryPolicy = options.retryPolicy ?? new RetryPolicy();
    this.sleepFn = options.sleep ?? defaultSleep;
  }

  async publish(options: PublishOptions): Promise<Message> {
    const key = options.idempotencyKey ?? generateIdempotencyKey();
    const body = JSON.stringify({ event_type: options.eventType, payload: options.payload });

    let attempt = 1;

    for (;;) {
      let response: Response;

      try {
        response = await this.send(options.applicationId, key, body);
      } catch (error) {
        if (attempt >= this.retryPolicy.maxAttempts()) {
          throw new TransportFailedError(messageOf(error), error);
        }

        await this.sleepFn(this.retryPolicy.delayMs(attempt));
        attempt++;

        continue;
      }

      if (response.status === 200 || response.status === 201) {
        const text = await response.text();

        return messageFromResponseBody(text, response.status === 200);
      }

      if (!this.retryPolicy.isRetryable(response.status) || attempt >= this.retryPolicy.maxAttempts()) {
        throw await exceptionFor(response);
      }

      await this.sleepFn(this.retryPolicy.delayMs(attempt, retryAfterSeconds(response)));
      attempt++;
    }
  }

  private send(applicationId: string, idempotencyKey: string, body: string): Promise<Response> {
    const url = `${this.baseUrl.replace(/\/+$/, "")}/v1/apps/${applicationId}/messages`;

    return this.fetchFn(url, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${this.apiKey}`,
        "Content-Type": "application/json",
        "Idempotency-Key": idempotencyKey,
      },
      body,
    });
  }
}

function defaultSleep(milliseconds: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, milliseconds);
  });
}

function generateIdempotencyKey(): string {
  return randomBytes(16).toString("hex");
}

function retryAfterSeconds(response: Response): number | undefined {
  const header = response.headers.get("Retry-After");

  if (header === null || !/^\d+$/.test(header)) {
    return undefined;
  }

  return Number.parseInt(header, 10);
}

async function exceptionFor(response: Response): Promise<PostBoxError> {
  const status = response.status;
  const body = await decodeBody(response);
  const message = typeof body.message === "string" ? body.message : `PostBox answered HTTP ${status}.`;
  const headers = flattenHeaders(response);

  if (status === 401) return new AuthenticationFailedError(message, headers);
  if (status === 402) return new QuotaExhaustedError(message, headers);
  if (status === 404) return new NotFoundError(message, headers);
  if (status === 409) return new IdempotencyConflictError(message, headers);
  if (status === 422) return new ValidationFailedError(message, errorsFrom(body), headers);
  if (status === 429) return new RateLimitedError(message, retryAfterSeconds(response) ?? null, headers);
  if (status >= 500) return new ServerError(message, status, headers);

  return new UnexpectedResponseError(message, status, headers);
}

async function decodeBody(response: Response): Promise<Record<string, unknown>> {
  try {
    const decoded: unknown = JSON.parse(await response.text());

    return typeof decoded === "object" && decoded !== null ? (decoded as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

function errorsFrom(body: Record<string, unknown>): Record<string, string[]> {
  const errors = body.errors;

  return typeof errors === "object" && errors !== null ? (errors as Record<string, string[]>) : {};
}

function flattenHeaders(response: Response): Record<string, string> {
  const headers: Record<string, string> = {};

  response.headers.forEach((value, name) => {
    headers[name] = value;
  });

  return headers;
}

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}
