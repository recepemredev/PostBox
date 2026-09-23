import type { components } from "./types/api.js";

/**
 * The wire shape MessageResource actually answers with — sourced from the
 * generated contract, never hand-typed, per conventions.md. `id`, `source`
 * and `created_at` type as plain `string` there because Scramble has no
 * richer PHP type to infer a `msg_` pattern or a date-time format from; this
 * alias exists so that gap is visible at one name rather than repeated.
 */
type MessageResourceBody = components["schemas"]["MessageResource"];

/**
 * publish()'s return value. `replayed` is never part of the wire shape — it
 * comes from which status code answered (200 vs 201), which is a fact about
 * the request/response exchange, not a field the server ever returns.
 */
export interface Message {
  readonly id: string;
  readonly eventType: string;
  readonly source: string;
  readonly createdAt: string;
  readonly replayed: boolean;
}

export function messageFromResponseBody(body: string, replayed: boolean): Message {
  const decoded: unknown = JSON.parse(body);

  if (!isMessageResourceBody(decoded)) {
    throw new Error("PostBox answered 200/201 with a body that is not a message resource.");
  }

  return {
    id: decoded.id,
    eventType: decoded.event_type,
    source: decoded.source,
    createdAt: decoded.created_at,
    replayed,
  };
}

function isMessageResourceBody(value: unknown): value is MessageResourceBody {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const record = value as Record<string, unknown>;

  return (
    typeof record.id === "string" &&
    typeof record.event_type === "string" &&
    typeof record.source === "string" &&
    typeof record.created_at === "string"
  );
}
