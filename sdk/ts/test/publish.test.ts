import { beforeEach, describe, expect, it } from "vitest";
import {
  AuthenticationFailedError,
  IdempotencyConflictError,
  NotFoundError,
  QuotaExhaustedError,
  RateLimitedError,
  ServerError,
  TransportFailedError,
  ValidationFailedError,
} from "../src/errors.js";
import { PostBox } from "../src/PostBox.js";
import { RetryPolicy } from "../src/RetryPolicy.js";
import { createFakeFetch, type FakeFetch, jsonResponse } from "./support/fakeFetch.js";

function postBox(fake: FakeFetch, retryPolicy?: RetryPolicy, sleep?: (ms: number) => Promise<void>): PostBox {
  return new PostBox({
    apiKey: "pbk_test_key",
    baseUrl: "https://api.postbox.test",
    fetch: fake.fetch,
    retryPolicy,
    sleep,
  });
}

const noSleep = async (): Promise<void> => {};

const aMessageBody = {
  id: "msg_01test",
  event_type: "invoice.paid",
  source: "api",
  created_at: "2026-09-23T10:00:00Z",
};

describe("publish", () => {
  let fake: FakeFetch;

  beforeEach(() => {
    fake = createFakeFetch();
  });

  it("returns a Message and marks it not replayed on 201", async () => {
    fake.queueResponse(jsonResponse(201, aMessageBody));

    const message = await postBox(fake).publish({
      applicationId: "app_01test",
      eventType: "invoice.paid",
      payload: { total: 4200 },
    });

    expect(message).toEqual({
      id: "msg_01test",
      eventType: "invoice.paid",
      source: "api",
      createdAt: "2026-09-23T10:00:00Z",
      replayed: false,
    });
  });

  it("marks the Message replayed on 200, the idempotent-replay status", async () => {
    fake.queueResponse(jsonResponse(200, aMessageBody, { "Idempotent-Replay": "true" }));

    const message = await postBox(fake).publish({
      applicationId: "app_01test",
      eventType: "invoice.paid",
      payload: { total: 4200 },
    });

    expect(message.replayed).toBe(true);
  });

  it("sends the given idempotency key unchanged, never generating its own", async () => {
    fake.queueResponse(jsonResponse(201, aMessageBody));

    await postBox(fake).publish({
      applicationId: "app_01test",
      eventType: "invoice.paid",
      payload: { total: 4200 },
      idempotencyKey: "my-own-key",
    });

    expect(fake.requests[0]?.headers["idempotency-key"]).toBe("my-own-key");
  });

  it("generates an idempotency key when none is given", async () => {
    fake.queueResponse(jsonResponse(201, aMessageBody));

    await postBox(fake).publish({ applicationId: "app_01test", eventType: "invoice.paid", payload: { total: 4200 } });

    expect(fake.requests[0]?.headers["idempotency-key"]).toBeTruthy();
  });

  it("carries the API key as a bearer token and the body as event_type plus payload", async () => {
    fake.queueResponse(jsonResponse(201, aMessageBody));

    await postBox(fake).publish({ applicationId: "app_01test", eventType: "invoice.paid", payload: { total: 4200 } });

    const request = fake.requests[0]!;

    expect(request.method).toBe("POST");
    expect(request.url).toBe("https://api.postbox.test/v1/apps/app_01test/messages");
    expect(request.headers.authorization).toBe("Bearer pbk_test_key");
    expect(JSON.parse(request.body)).toEqual({ event_type: "invoice.paid", payload: { total: 4200 } });
  });

  it.each([
    [401, AuthenticationFailedError] as const,
    [402, QuotaExhaustedError] as const,
    [404, NotFoundError] as const,
    [409, IdempotencyConflictError] as const,
  ])("maps status %d to its own exception, without retrying it", async (status, ErrorClass) => {
    fake.queueResponse(jsonResponse(status, { message: "a reason PostBox gave" }));

    try {
      await postBox(fake).publish({ applicationId: "app_01test", eventType: "invoice.paid", payload: { total: 4200 } });
      expect.fail(`Expected ${ErrorClass.name} to be thrown.`);
    } catch (error) {
      expect(error).toBeInstanceOf(ErrorClass);
      expect((error as Error).message).toBe("a reason PostBox gave");
    }

    expect(fake.requests).toHaveLength(1);
  });

  it("carries the field errors from a 422 onto ValidationFailedError", async () => {
    fake.queueResponse(
      jsonResponse(422, {
        message: "The given data was invalid.",
        errors: { payload: ["The payload field is required."] },
      }),
    );

    try {
      await postBox(fake).publish({ applicationId: "app_01test", eventType: "invoice.paid", payload: {} });
      expect.fail("Expected ValidationFailedError to be thrown.");
    } catch (error) {
      expect(error).toBeInstanceOf(ValidationFailedError);
      expect((error as ValidationFailedError).errors).toEqual({ payload: ["The payload field is required."] });
    }
  });

  it("retries a 5xx up to the retry policy, then throws ServerError", async () => {
    fake.queueResponse(jsonResponse(500, { message: "internal error" }));
    fake.queueResponse(jsonResponse(502, { message: "bad gateway" }));
    fake.queueResponse(jsonResponse(503, { message: "unavailable" }));

    const sleeps: number[] = [];
    const policy = new RetryPolicy(3, 10, 2, 100);

    try {
      await postBox(fake, policy, async (ms) => {
        sleeps.push(ms);
      }).publish({ applicationId: "app_01test", eventType: "invoice.paid", payload: { total: 4200 } });
      expect.fail("Expected ServerError to be thrown.");
    } catch (error) {
      expect(error).toBeInstanceOf(ServerError);
      expect((error as Error).message).toBe("unavailable");
    }

    expect(fake.requests).toHaveLength(3);
    expect(sleeps).toEqual([10, 20]);
  });

  it("succeeds once a retried 5xx is followed by a 201", async () => {
    fake.queueResponse(jsonResponse(500, { message: "internal error" }));
    fake.queueResponse(jsonResponse(201, aMessageBody));

    const policy = new RetryPolicy(3, 1, 2, 10);
    const message = await postBox(fake, policy, noSleep).publish({
      applicationId: "app_01test",
      eventType: "invoice.paid",
      payload: { total: 4200 },
    });

    expect(message.id).toBe("msg_01test");
    expect(fake.requests).toHaveLength(2);
  });

  it("retries with the same idempotency key on every attempt", async () => {
    fake.queueResponse(jsonResponse(500, { message: "internal error" }));
    fake.queueResponse(jsonResponse(201, aMessageBody));

    const policy = new RetryPolicy(3, 1, 2, 10);
    await postBox(fake, policy, noSleep).publish({
      applicationId: "app_01test",
      eventType: "invoice.paid",
      payload: { total: 4200 },
    });

    const keys = fake.requests.map((request) => request.headers["idempotency-key"]);

    expect(keys[0]).toBe(keys[1]);
    expect(keys[0]).toBeTruthy();
  });

  it("retries a transport failure, then throws TransportFailedError once every attempt fails", async () => {
    fake.queueError(new Error("connection refused"));
    fake.queueError(new Error("connection refused"));
    fake.queueError(new Error("connection refused"));

    const policy = new RetryPolicy(3, 1, 2, 10);

    try {
      await postBox(fake, policy, noSleep).publish({
        applicationId: "app_01test",
        eventType: "invoice.paid",
        payload: { total: 4200 },
      });
      expect.fail("Expected TransportFailedError to be thrown.");
    } catch (error) {
      expect(error).toBeInstanceOf(TransportFailedError);
      expect((error as Error).message).toBe("connection refused");
    }

    expect(fake.requests).toHaveLength(3);
  });

  it("retries a 429 up to the retry policy honouring Retry-After, then throws RateLimitedError", async () => {
    fake.queueResponse(jsonResponse(429, { message: "rate limited" }, { "Retry-After": "2" }));
    fake.queueResponse(jsonResponse(429, { message: "rate limited" }, { "Retry-After": "3" }));
    fake.queueResponse(jsonResponse(429, { message: "rate limited" }, { "Retry-After": "4" }));

    const sleeps: number[] = [];
    const policy = new RetryPolicy(3, 10, 2, 100);

    try {
      await postBox(fake, policy, async (ms) => {
        sleeps.push(ms);
      }).publish({ applicationId: "app_01test", eventType: "invoice.paid", payload: { total: 4200 } });
      expect.fail("Expected RateLimitedError to be thrown.");
    } catch (error) {
      expect(error).toBeInstanceOf(RateLimitedError);
      expect((error as RateLimitedError).retryAfter).toBe(4);
    }

    // Retry-After overrides the computed delay outright on every attempt.
    expect(sleeps).toEqual([2_000, 3_000]);
  });

  it.each([401, 402, 404, 409, 422])("never retries a %d", async (status) => {
    fake.queueResponse(jsonResponse(status, { message: "refused" }));

    await expect(
      postBox(fake).publish({ applicationId: "app_01test", eventType: "invoice.paid", payload: { total: 4200 } }),
    ).rejects.toThrow();

    expect(fake.requests).toHaveLength(1);
  });
});
