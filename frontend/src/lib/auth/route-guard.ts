/**
 * The session cookie's name: `Str::slug(APP_NAME).'-session'`
 * (`backend/config/session.php`), and `APP_NAME=PostBox` in `.env` — so
 * `postbox-session`. A literal rather than an env var: it is a fixed
 * derivation from a value that itself never changes per environment.
 */
export const SESSION_COOKIE_NAME = "postbox-session";

/**
 * Every route under (dashboard) needs a session; (auth)'s own routes never
 * do. A pure function so the redirect decision is testable without a real
 * NextRequest/NextResponse pair — middleware.ts is the thin, untested layer
 * that calls this and acts on the answer.
 */
export function requiresSession(pathname: string): boolean {
  return !pathname.startsWith("/login") && pathname !== "/favicon.ico";
}

/**
 * The cookie's mere presence is not proof of a valid session — only a fast
 * path that saves a round trip for the common case of no cookie at all.
 * The backend's own 401 (shouldRenderJsonWhen(true), always JSON, never a
 * redirect) is what a Server Component still has to handle if the cookie
 * is present but the session behind it has expired.
 */
export function shouldRedirectToLogin(pathname: string, hasSessionCookie: boolean): boolean {
  return requiresSession(pathname) && !hasSessionCookie;
}
