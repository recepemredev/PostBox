import { describe, expect, it } from "vitest";
import { RetryPolicy } from "../src/RetryPolicy.js";

describe("RetryPolicy", () => {
  const policy = new RetryPolicy(3, 500, 2, 8_000);

  it("computes the delay before the next attempt as base times factor to the attempt minus one", () => {
    expect(policy.delayMs(1)).toBe(500);
    expect(policy.delayMs(2)).toBe(1_000);
    expect(policy.delayMs(3)).toBe(2_000);
  });

  it("clamps at the ceiling however high the attempt number climbs", () => {
    expect(policy.delayMs(10)).toBe(8_000);
  });

  it("lets a Retry-After value override the computed delay outright", () => {
    expect(policy.delayMs(1, 5)).toBe(5_000);
  });

  it.each([null, 429, 500, 503])("treats %s as retryable", (status) => {
    expect(policy.isRetryable(status)).toBe(true);
  });

  it.each([400, 401, 402, 404, 409, 422])("never treats %d as retryable", (status) => {
    expect(policy.isRetryable(status)).toBe(false);
  });

  it("treats 200 as not retryable — a successful response never reaches the check", () => {
    expect(policy.isRetryable(200)).toBe(false);
  });
});
