/**
 * Parses one raw `Set-Cookie` header string into the shape Next's
 * `cookies().set()` accepts. A pure function, tested on its own: the backend
 * response's Set-Cookie is what has to reach the browser after a login or a
 * logout (both rotate the session cookie) or the CSRF priming call
 * (middleware.ts), since the fetch that received it ran on this server, not
 * in the browser.
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
  const value = pair.slice(separatorIndex + 1);
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
