import { cache } from "react";
import { me } from "@/lib/api/server";

/**
 * Wrapped in React's own `cache()` so every Server Component in one request
 * that asks "who is this and what may they do" shares a single call to
 * GET /v1/me, the same request-scoped memoisation `Permissions` itself uses
 * on the backend — not a second cache layer duplicating that one's job,
 * just its frontend counterpart for a different process.
 */
export const getIdentity = cache(async () => {
  return me();
});

export function hasPermission(permissions: string[], permission: string): boolean {
  return permissions.includes(permission);
}
