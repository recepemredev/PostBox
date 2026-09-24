import { describe, expect, it } from "vitest";
import { isHealthProbe, requiresSession, shouldRedirectToLogin } from "./route-guard";

describe("isHealthProbe", () => {
  it("matches only the healthcheck's own path", () => {
    expect(isHealthProbe("/health")).toBe(true);
  });

  it("does not match a dashboard or auth path", () => {
    expect(isHealthProbe("/login")).toBe(false);
    expect(isHealthProbe("/applications")).toBe(false);
  });
});

describe("requiresSession", () => {
  it("does not require a session on /login", () => {
    expect(requiresSession("/login")).toBe(false);
  });

  it("requires a session on every dashboard path", () => {
    expect(requiresSession("/applications")).toBe(true);
    expect(requiresSession("/endpoints/ep_01j0000000000000000000000")).toBe(true);
  });
});

describe("shouldRedirectToLogin", () => {
  it("redirects a dashboard path with no session cookie", () => {
    expect(shouldRedirectToLogin("/applications", false)).toBe(true);
  });

  it("does not redirect a dashboard path with a session cookie", () => {
    expect(shouldRedirectToLogin("/applications", true)).toBe(false);
  });

  it("never redirects /login itself, cookie or not", () => {
    expect(shouldRedirectToLogin("/login", false)).toBe(false);
    expect(shouldRedirectToLogin("/login", true)).toBe(false);
  });
});
