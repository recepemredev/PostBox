import Link from "next/link";
import type { MessageFilters } from "@/lib/messages/filters";

/**
 * A plain GET form — no client JS, no Server Action, since filtering is a
 * read, not a mutation (conventions.md's Server-Action rule is about
 * mutations). Submitting navigates to /messages?... the same way a browser
 * always has, and leaving cursor and limit out of the form is what resets
 * pagination to a fresh first page the moment a filter changes.
 */
export function MessageFiltersForm({
  filters,
  eventTypes,
}: {
  filters: MessageFilters;
  eventTypes: string[];
}) {
  const hasFilters =
    filters.endpoint !== null || filters.eventType !== null || filters.status !== null || filters.from !== null || filters.to !== null;

  return (
    <form method="get" className="flex flex-wrap items-end gap-3 rounded-container border border-border bg-surface p-4">
      <div className="space-y-1">
        <label htmlFor="endpoint" className="block text-xs font-medium text-text-muted">
          Endpoint ID
        </label>
        <input
          id="endpoint"
          name="endpoint"
          type="text"
          placeholder="ep_..."
          defaultValue={filters.endpoint ?? ""}
          className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
        />
      </div>

      <div className="space-y-1">
        <label htmlFor="event_type" className="block text-xs font-medium text-text-muted">
          Event type
        </label>
        <select
          id="event_type"
          name="event_type"
          defaultValue={filters.eventType ?? ""}
          className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
        >
          <option value="">Any</option>
          {eventTypes.map((name) => (
            <option key={name} value={name}>
              {name}
            </option>
          ))}
        </select>
      </div>

      <div className="space-y-1">
        <label htmlFor="status" className="block text-xs font-medium text-text-muted">
          Status
        </label>
        <select
          id="status"
          name="status"
          defaultValue={filters.status ?? ""}
          className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
        >
          <option value="">Any</option>
          <option value="pending">Pending</option>
          <option value="succeeded">Succeeded</option>
          <option value="exhausted">Failed</option>
        </select>
      </div>

      <div className="space-y-1">
        <label htmlFor="from" className="block text-xs font-medium text-text-muted">
          From
        </label>
        <input
          id="from"
          name="from"
          type="datetime-local"
          defaultValue={filters.from ?? ""}
          className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
        />
      </div>

      <div className="space-y-1">
        <label htmlFor="to" className="block text-xs font-medium text-text-muted">
          To
        </label>
        <input
          id="to"
          name="to"
          type="datetime-local"
          defaultValue={filters.to ?? ""}
          className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
        />
      </div>

      <button type="submit" className="rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-fg">
        Apply filters
      </button>

      {hasFilters ? (
        <Link href="/messages" className="text-sm text-text-muted hover:text-text">
          Clear
        </Link>
      ) : null}
    </form>
  );
}
