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

  it("percent-decodes the value once, matching Laravel's own rawurlencode", () => {
    // Laravel's encrypted cookie payload is base64 (+, /, = included), which
    // rawurlencode() escapes for the wire. cookies().set() re-encodes
    // whatever value it is given when it serialises its own Set-Cookie
    // header, so the value handed to it here has to already be the decoded,
    // logical one -- passing the still-encoded wire string through
    // unchanged double-encodes it, and a real browser then stores and
    // resends bytes Laravel's own decrypt never produced (found live: every
    // browser-direct request to the backend answered 401 after a login,
    // since only Set-Cookie forwarded through this function -- not
    // middleware.ts's own raw passthrough for CSRF priming -- was affected).
    const parsed = parseSetCookie("postbox-session=abc%2Bdef%2Fghi%3D; path=/; httponly");

    expect(parsed?.value).toBe("abc+def/ghi=");
  });

  it("returns null for a header with no name=value pair", () => {
    expect(parseSetCookie("")).toBeNull();
  });
});
