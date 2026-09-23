/**
 * Parses one raw `Set-Cookie` header string into the shape Next's
 * `cookies().set()` accepts. A pure function, tested on its own: the backend
 * response's Set-Cookie is what has to reach the browser after a login or a
 * logout (both rotate the session cookie) or the CSRF priming call
 * (middleware.ts), since the fetch that received it ran on this server, not
 * in the browser.
 *
 * The value is percent-decoded here: Laravel's own Set-Cookie already
 * percent-encodes it once for the wire (rawurlencode), and `cookies().set()`
 * treats whatever string it is given as the logical value, encoding it once
 * more when it serialises its own outgoing Set-Cookie header. Handing it the
 * already-encoded string double-encodes it — invisible to every Server
 * Action and Server Component, since those read the cookie back through
 * Next's own store rather than off the wire, but fatal to the one thing this
 * app deliberately lets the browser fetch directly (conventions.md's SSE
 * stream exception, Step 15): the browser stores and resends the literal,
 * doubly-encoded bytes, and Laravel's own decrypt of the resulting session
 * cookie fails, answering every request with it as unauthenticated. Found
 * live, not in a test — curl never exercises this path, since it replays a
 * cookie jar it captured itself rather than round-tripping through a real
 * browser's own Set-Cookie handling.
 */
export type ParsedCookie = {
  name: string;
  value: string;
  options: {
    path?: string;
    domain?: string;
    maxAge?: number;
    expires?: Date;
    httpOnly?: boolean;
    secure?: boolean;
    sameSite?: "lax" | "strict" | "none";
  };
};

export function parseSetCookie(raw: string): ParsedCookie | null {
  const parts = raw.split(";").map((part) => part.trim());
  const [pair, ...attributes] = parts;

  if (!pair) {
    return null;
  }

  const separatorIndex = pair.indexOf("=");

  if (separatorIndex === -1) {
    return null;
  }

  const name = pair.slice(0, separatorIndex);
  const rawValue = pair.slice(separatorIndex + 1);
  const value = decodeURIComponent(rawValue);
  const options: ParsedCookie["options"] = {};

  for (const attribute of attributes) {
    const [rawKey, rawValue] = attribute.split("=");
    const key = rawKey.toLowerCase();

    switch (key) {
      case "path":
        options.path = rawValue;
        break;
      case "domain":
        options.domain = rawValue;
        break;
      case "max-age":
        options.maxAge = Number(rawValue);
        break;
      case "expires":
        options.expires = new Date(rawValue);
        break;
      case "secure":
        options.secure = true;
        break;
      case "httponly":
        options.httpOnly = true;
        break;
      case "samesite":
        options.sameSite = rawValue?.toLowerCase() as ParsedCookie["options"]["sameSite"];
        break;
      default:
        break;
    }
  }

  return { name, value, options };
}
