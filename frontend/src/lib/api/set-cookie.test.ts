import { describe, expect, it } from "vitest";
import { parseSetCookie } from "./set-cookie";

describe("parseSetCookie", () => {
  it("parses name, value and the common attributes", () => {
    const parsed = parseSetCookie(
      "XSRF-TOKEN=abc123; expires=Thu, 01-Jan-2026 00:00:00 GMT; Max-Age=7200; path=/; secure; samesite=lax",
    );

    expect(parsed).not.toBeNull();
    expect(parsed?.name).toBe("XSRF-TOKEN");
    expect(parsed?.value).toBe("abc123");
    expect(parsed?.options.path).toBe("/");
    expect(parsed?.options.maxAge).toBe(7200);
    expect(parsed?.options.secure).toBe(true);
    expect(parsed?.options.sameSite).toBe("lax");
  });

  it("marks httpOnly when the attribute is present", () => {
    const parsed = parseSetCookie("postbox-session=xyz; path=/; httponly");

    expect(parsed?.options.httpOnly).toBe(true);
  });

  it("returns null for a header with no name=value pair", () => {
    expect(parseSetCookie("")).toBeNull();
  });
});
