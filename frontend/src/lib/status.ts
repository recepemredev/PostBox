import type { StatusTone } from "@/components/StatusBadge";

/**
 * The state colour semantics table from design.md, as pure lookups —
 * StatusBadge only ever renders a {tone, label} pair, and these are the one
 * place each domain enum's meaning turns into one.
 */

/**
 * attemptCount distinguishes design.md's two pending meanings — "queued, not
 * yet attempted" (info) from "scheduled retry pending" (warning) — rather
 * than growing a second, near-identical function for the same status
 * column. Every existing call site omits it and keeps today's behaviour.
 */
export function deliveryStatusTone(status: string, attemptCount = 0): { tone: StatusTone; label: string } {
  switch (status) {
    case "succeeded":
      return { tone: "success", label: "Delivered" };
    case "exhausted":
      return { tone: "danger", label: "Failed" };
    case "pending":
      return attemptCount > 0 ? { tone: "warning", label: "Retrying" } : { tone: "info", label: "Pending" };
    default:
      return { tone: "neutral", label: status };
  }
}

/**
 * A message has no status column of its own (there is no MessageStatus) —
 * the list reads it off the delivery counts MessageSummaryResource already
 * carries, worst outcome first: any exhausted delivery makes the row
 * "Failed" even if others succeeded, because that is the row an operator
 * scanning for trouble needs to see.
 */
export function messageDeliveryTone(deliveries: {
  total: number;
  succeeded: number;
  pending: number;
  exhausted: number;
}): { tone: StatusTone; label: string } {
  if (deliveries.exhausted > 0) {
    return { tone: "danger", label: "Failed" };
  }

  if (deliveries.pending > 0) {
    return { tone: "info", label: "Pending" };
  }

  if (deliveries.total === 0) {
    return { tone: "neutral", label: "No subscribers" };
  }

  return { tone: "success", label: "Delivered" };
}

/**
 * AttemptOutcome's own cases (backend/app/Enums/AttemptOutcome.php). Blocked
 * is danger, not neutral: PostBox refusing to send — a missing secret, a
 * target AddressGuard rejects — is exactly as much a problem for an
 * operator to notice as a failed response is (modules.md: "a delivery
 * attempt is never silently skipped").
 */
export function attemptOutcomeTone(outcome: string): { tone: StatusTone; label: string } {
  switch (outcome) {
    case "succeeded":
      return { tone: "success", label: "Succeeded" };
    case "failed":
      return { tone: "danger", label: "Failed" };
    case "timeout":
      return { tone: "danger", label: "Timed out" };
    case "dns_error":
      return { tone: "danger", label: "DNS error" };
    case "tls_error":
      return { tone: "danger", label: "TLS error" };
    case "connection_error":
      return { tone: "danger", label: "Connection error" };
    case "blocked":
      return { tone: "danger", label: "Blocked" };
    default:
      return { tone: "neutral", label: outcome };
  }
}

export function endpointStatusTone(status: string): { tone: StatusTone; label: string } {
  switch (status) {
    case "enabled":
      return { tone: "success", label: "Enabled" };
    case "disabled":
      return { tone: "neutral", label: "Disabled" };
    default:
      return { tone: "neutral", label: status };
  }
}

export function breakerStateTone(state: string): { tone: StatusTone; label: string } {
  switch (state) {
    case "closed":
      return { tone: "success", label: "Closed" };
    case "open":
      return { tone: "danger", label: "Open" };
    case "half_open":
      return { tone: "warning", label: "Half-open" };
    default:
      return { tone: "neutral", label: state };
  }
}
