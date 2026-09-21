/**
 * The message list's own filters (endpoint, event type, status, time
 * range) plus the cursor and limit that page it — parsed once from the
 * URL's own searchParams, so the Server Component, the pagination links
 * and the range-replay button all read the identical, already-validated
 * shape rather than each re-reading the raw query string.
 */

const STATUS_VALUES = ["pending", "succeeded", "exhausted"] as const;

export type StatusFilter = (typeof STATUS_VALUES)[number];

export type MessageFilters = {
  endpoint: string | null;
  eventType: string | null;
  status: StatusFilter | null;
  from: string | null;
  to: string | null;
  cursor: string | null;
  limit: number | null;
};

type SearchParams = Record<string, string | string[] | undefined>;

function firstValue(value: string | string[] | undefined): string | null {
  const raw = Array.isArray(value) ? value[0] : value;

  return raw !== undefined && raw !== "" ? raw : null;
}

function isStatusFilter(value: string): value is StatusFilter {
  return (STATUS_VALUES as readonly string[]).includes(value);
}

export function parseMessageFilters(searchParams: SearchParams): MessageFilters {
  const status = firstValue(searchParams.status);
  const rawLimit = firstValue(searchParams.limit);
  const parsedLimit = rawLimit !== null ? Number.parseInt(rawLimit, 10) : NaN;

  return {
    endpoint: firstValue(searchParams.endpoint),
    eventType: firstValue(searchParams.event_type),
    status: status !== null && isStatusFilter(status) ? status : null,
    from: firstValue(searchParams.from),
    to: firstValue(searchParams.to),
    cursor: firstValue(searchParams.cursor),
    limit: Number.isInteger(parsedLimit) && parsedLimit > 0 ? parsedLimit : null,
  };
}

/**
 * The query string listMessages() sends to GET /v1/messages — every filter,
 * the cursor and the limit alike. A pagination link reuses this with only
 * `cursor` overridden, which is what keeps "next page" honouring whatever
 * filters are already active.
 */
export function toQueryString(filters: MessageFilters): string {
  const params = new URLSearchParams();

  if (filters.endpoint !== null) params.set("endpoint", filters.endpoint);
  if (filters.eventType !== null) params.set("event_type", filters.eventType);
  if (filters.status !== null) params.set("status", filters.status);
  if (filters.from !== null) params.set("from", filters.from);
  if (filters.to !== null) params.set("to", filters.to);
  if (filters.cursor !== null) params.set("cursor", filters.cursor);
  if (filters.limit !== null) params.set("limit", String(filters.limit));

  const query = params.toString();

  return query === "" ? "" : `?${query}`;
}

/**
 * Whether this filter set maps onto exactly what range replay
 * (POST /v1/endpoints/{endpoint}/replays) can express: one endpoint, only
 * exhausted deliveries, and a bounded time window — it has no event-type
 * parameter and no status other than "exhausted" (D81), and widening it is
 * deferred past Step 17 (backlog.md). The range-replay button stays
 * disabled rather than firing a request that would silently ignore part of
 * what the screen shows (D122).
 */
export function canReplayRange(filters: MessageFilters): boolean {
  return filters.endpoint !== null && filters.status === "exhausted" && filters.from !== null && filters.to !== null;
}
