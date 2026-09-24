import { NextResponse } from "next/server";

/**
 * The container healthcheck's target (compose.yaml). Proves only that the
 * Next server itself is up and serving — no backend call, no cookie, no
 * redirect. `/` cannot serve this role: every path but /login redirects
 * there, and middleware's CSRF priming then calls the backend through
 * nginx — which depends on this very healthcheck passing first
 * (service_healthy), a cycle neither side can ever win.
 */
export function GET(): NextResponse {
  return new NextResponse(null, { status: 200 });
}
