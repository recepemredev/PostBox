import { NextResponse, type NextRequest } from "next/server";
import { SESSION_COOKIE_NAME, shouldRedirectToLogin } from "@/lib/auth/route-guard";
import { apiBaseUrl, buildApiUrl } from "@/lib/api/url";

/**
 * Two jobs, both here because both have to run before a page renders and
 * both need to act on the response's own cookies — a Server Component
 * cannot set one, only a Server Action, a Route Handler, or middleware can.
 *
 * 1. Auth fast path: no session cookie, no dashboard page — redirect to
 *    /login before Next spends a render on a page a Server Component would
 *    only 401 on anyway (route-guard.ts explains why the cookie's presence
 *    is a fast path, not the real check).
 * 2. CSRF priming: the backend's XSRF-TOKEN cookie is what every mutating
 *    Server Action later carries as X-XSRF-TOKEN. Nothing in this app makes
 *    a client-side fetch to ask the backend for it (conventions.md), so
 *    middleware fetches /v1/csrf-cookie itself and forwards its Set-Cookie
 *    onto the response the browser actually receives — the one place in
 *    this app a Set-Cookie can be proxied from the backend to the browser.
 */
export async function middleware(request: NextRequest): Promise<NextResponse> {
  const hasSessionCookie = request.cookies.has(SESSION_COOKIE_NAME);

  if (shouldRedirectToLogin(request.nextUrl.pathname, hasSessionCookie)) {
    return NextResponse.redirect(new URL("/login", request.url));
  }

  const response = NextResponse.next();

  if (!request.cookies.has("XSRF-TOKEN")) {
    await primeCsrfCookie(response);
  }

  return response;
}

async function primeCsrfCookie(response: NextResponse): Promise<void> {
  try {
    const backendResponse = await fetch(buildApiUrl(apiBaseUrl(), "/v1/csrf-cookie"), {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    for (const cookie of backendResponse.headers.getSetCookie()) {
      response.headers.append("set-cookie", cookie);
    }
  } catch {
    // Priming failed (backend unreachable). The page still renders; the
    // first mutation will fail its own CSRF check and surface a plain
    // error, rather than the whole request pipeline failing here.
  }
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
