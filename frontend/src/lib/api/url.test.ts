import { describe, expect, it } from "vitest";
import { buildApiUrl } from "./url";

describe("buildApiUrl", () => {
  it("joins a base URL and a path", () => {
    expect(buildApiUrl("http://nginx:8080/api", "/v1/me")).toBe("http://nginx:8080/api/v1/me");
  });

  it("tolerates a trailing slash on the base URL", () => {
    expect(buildApiUrl("http://nginx:8080/api/", "/v1/me")).toBe("http://nginx:8080/api/v1/me");
  });

  it("tolerates a path with no leading slash", () => {
    expect(buildApiUrl("http://nginx:8080/api", "v1/me")).toBe("http://nginx:8080/api/v1/me");
  });

  it("throws when the base URL is not set, naming the env var", () => {
    expect(() => buildApiUrl(undefined, "/v1/me")).toThrow(/POSTBOX_SERVER_API_URL/);
  });
});
