import "server-only";

import { cookies } from "next/headers";
import { apiErrorFrom } from "@/lib/api/errors";
import { parseSetCookie } from "@/lib/api/set-cookie";
import { apiBaseUrl, buildApiUrl } from "@/lib/api/url";
import type { components } from "@/types/api";

/**
 * The one place this app talks to the backend. Every call forwards the
 * browser's own cookies (session + XSRF-TOKEN, primed by middleware.ts) and,
 * for a mutating method, the X-XSRF-TOKEN header Laravel's CSRF middleware
 * checks against that cookie — the same pair a same-origin browser request
 * would carry on its own, reconstructed here because the fetch itself runs
 * on the server, not in the browser (conventions.md: no client-side fetch to
 * the backend except the SSE stream).
 *
 * Route paths are relative to `/v1` — every Catalog and Identity route this
 * app calls lives there.
 */
async function apiFetch<T>(
  method: "GET" | "POST" | "PATCH" | "PUT" | "DELETE",
  path: string,
  body?: unknown,
): Promise<T> {
  const cookieStore = await cookies();
  const cookieHeader = cookieStore
    .getAll()
    .map((cookie) => `${cookie.name}=${cookie.value}`)
    .join("; ");

  const headers: Record<string, string> = {
    Accept: "application/json",
    Cookie: cookieHeader,
  };

  if (method !== "GET") {
    const csrfToken = cookieStore.get("XSRF-TOKEN")?.value;

    if (csrfToken) {
      headers["X-XSRF-TOKEN"] = decodeURIComponent(csrfToken);
    }
  }

  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  const response = await fetch(buildApiUrl(apiBaseUrl(), `/v1${path}`), {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
    cache: "no-store",
  });

  // login and logout both rotate the session cookie (AuthenticateUser
  // regenerates the session id; EndSession invalidates it). The fetch that
  // just received the new Set-Cookie ran here, on the server, so nothing
  // reaches the browser's own cookie jar until this forwards it — the same
  // proxying middleware.ts does for the CSRF cookie.
  await forwardSetCookies(response);

  if (response.status === 204) {
    return undefined as T;
  }

  const parsed: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    throw apiErrorFrom(response.status, parsed);
  }

  return parsed as T;
}

async function forwardSetCookies(response: Response): Promise<void> {
  const setCookies = response.headers.getSetCookie();

  if (setCookies.length === 0) {
    return;
  }

  try {
    const cookieStore = await cookies();

    for (const raw of setCookies) {
      const parsed = parseSetCookie(raw);

      if (parsed) {
        cookieStore.set(parsed.name, parsed.value, parsed.options);
      }
    }
  } catch {
    // Called from a Server Component, where cookies() is read-only —
    // nothing this app calls from one ever rotates a cookie in practice,
    // so there is nothing lost by this being a no-op here.
  }
}

type Schemas = components["schemas"];

/** @internal shape shared by every paginated Catalog list */
type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
};

/**
 * @internal shape shared by every cursor-paginated Ledger list
 * (conventions.md: cursor pagination for the append-only tables). Distinct
 * from Paginated<T> above — a keyset cursor has no page number or total to
 * carry, only whatever comes next.
 */
type CursorPaginated<T> = {
  data: T[];
  meta: { next_cursor: string | null };
};

// --- Identity ---------------------------------------------------------------

export function login(credentials: Schemas["LoginRequest"]): Promise<Schemas["IdentityResource"]> {
  return apiFetch("POST", "/login", credentials);
}

export function logout(): Promise<void> {
  return apiFetch("POST", "/logout");
}

export function me(): Promise<Schemas["IdentityResource"]> {
  return apiFetch("GET", "/me");
}

// --- Applications ------------------------------------------------------------

export function listApplications(page = 1): Promise<Paginated<Schemas["ApplicationResource"]>> {
  return apiFetch("GET", `/applications?page=${page}`);
}

export function createApplication(name: string): Promise<Schemas["ApplicationResource"]> {
  return apiFetch("POST", "/applications", { name } satisfies Schemas["StoreApplicationRequest"]);
}

export function getApplication(applicationId: string): Promise<Schemas["ApplicationResource"]> {
  return apiFetch("GET", `/applications/${applicationId}`);
}

export function renameApplication(applicationId: string, name: string): Promise<Schemas["ApplicationResource"]> {
  return apiFetch("PATCH", `/applications/${applicationId}`, { name } satisfies Schemas["UpdateApplicationRequest"]);
}

// --- Endpoints ---------------------------------------------------------------

export function listEndpoints(
  applicationId: string,
  page = 1,
): Promise<Paginated<Schemas["EndpointResource"]>> {
  return apiFetch("GET", `/applications/${applicationId}/endpoints?page=${page}`);
}

export function createEndpoint(
  applicationId: string,
  input: Schemas["StoreEndpointRequest"],
): Promise<Schemas["EndpointResource"]> {
  return apiFetch("POST", `/applications/${applicationId}/endpoints`, input);
}

export function getEndpoint(endpointId: string): Promise<Schemas["EndpointResource"]> {
  return apiFetch("GET", `/endpoints/${endpointId}`);
}

export function updateEndpoint(
  endpointId: string,
  changes: Schemas["UpdateEndpointRequest"],
): Promise<Schemas["EndpointResource"]> {
  return apiFetch("PATCH", `/endpoints/${endpointId}`, changes);
}

export function syncSubscriptions(endpointId: string, eventTypes: string[]): Promise<Schemas["EndpointResource"]> {
  return apiFetch("PUT", `/endpoints/${endpointId}/subscriptions`, {
    event_types: eventTypes,
  } satisfies Schemas["SyncSubscriptionsRequest"]);
}

export function sendTestEvent(endpointId: string, eventType: string): Promise<Schemas["TestEventResource"]> {
  return apiFetch("POST", `/endpoints/${endpointId}/test-events`, {
    event_type: eventType,
  } satisfies Schemas["SendTestEventRequest"]);
}

// --- Event types --------------------------------------------------------------

export function listEventTypes(page = 1): Promise<Paginated<Schemas["EventTypeResource"]>> {
  return apiFetch("GET", `/event-types?page=${page}`);
}

export function createEventType(name: string): Promise<Schemas["EventTypeResource"]> {
  return apiFetch("POST", "/event-types", { name } satisfies Schemas["StoreEventTypeRequest"]);
}

// --- Endpoint secrets ----------------------------------------------------------

export function listSecrets(
  endpointId: string,
  page = 1,
): Promise<Paginated<Schemas["EndpointSecretResource"]>> {
  return apiFetch("GET", `/endpoints/${endpointId}/secrets?page=${page}`);
}

export function issueSecret(endpointId: string): Promise<Schemas["IssuedEndpointSecretResource"]> {
  return apiFetch("POST", `/endpoints/${endpointId}/secrets`);
}

export function revokeSecret(endpointId: string, secretId: string): Promise<void> {
  return apiFetch("DELETE", `/endpoints/${endpointId}/secrets/${secretId}`);
}

// --- Messages (Step 14) -------------------------------------------------------

/**
 * `query` is built by `lib/messages/filters.ts`'s `toQueryString()` — every
 * filter, the cursor and the limit together, since a message list request
 * is one query string, not a pile of optional parameters this function
 * would have to reassemble itself.
 */
export function listMessages(query: string): Promise<CursorPaginated<Schemas["MessageSummaryResource"]>> {
  return apiFetch("GET", `/messages${query}`);
}

export function getMessage(messageId: string): Promise<Schemas["MessageDetailResource"]> {
  return apiFetch("GET", `/messages/${messageId}`);
}

export function listAttempts(
  deliveryId: string,
  query = "",
): Promise<CursorPaginated<Schemas["DeliveryAttemptResource"]>> {
  return apiFetch("GET", `/deliveries/${deliveryId}/attempts${query}`);
}

// --- Recovery (Step 9, buttons added in Step 14) ------------------------------

/**
 * D76/D122: "retry a single delivery" and "replay to every subscriber" are
 * the same call, endpoint present or absent — never two functions for one
 * request shape. Opens a new delivery; it never retries the row a caller
 * already has (D78).
 */
export function replayMessage(messageId: string, endpointId: string | null): Promise<Schemas["ReplayResource"]> {
  return apiFetch("POST", `/messages/${messageId}/replay`, {
    endpoint: endpointId ?? null,
  } satisfies Schemas["ReplayMessageRequest"]);
}

/**
 * One endpoint's own exhausted deliveries over a bounded window, capped at
 * `postbox.replay.max_deliveries_per_request` server-side (D80) — `from`
 * and `to` are whatever `canReplayRange()` already confirmed the screen's
 * own filters can express.
 */
export function replayRange(endpointId: string, from: string, to: string): Promise<Schemas["ReplayResource"]> {
  return apiFetch("POST", `/endpoints/${endpointId}/replays`, {
    from,
    to,
  } satisfies Schemas["ReplayRangeRequest"]);
}

// --- Operations (Step 17) ------------------------------------------------------

export function getOperations(): Promise<Schemas["OperationsResource"]> {
  return apiFetch("GET", "/operations");
}
