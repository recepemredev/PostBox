import type { StatusTone } from "@/components/StatusBadge";

/**
 * The state colour semantics table from design.md, as pure lookups —
 * StatusBadge only ever renders a {tone, label} pair, and these are the one
 * place each domain enum's meaning turns into one.
 */

export function deliveryStatusTone(status: string): { tone: StatusTone; label: string } {
  switch (status) {
    case "succeeded":
      return { tone: "success", label: "Delivered" };
    case "exhausted":
      return { tone: "danger", label: "Failed" };
    case "pending":
      return { tone: "info", label: "Pending" };
    default:
      return { tone: "neutral", label: status };
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
