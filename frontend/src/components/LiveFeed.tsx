"use client";

import Link from "next/link";
import { formatDuration } from "@/lib/duration";
import type { FeedState } from "@/lib/stream/feed";
import { attemptOutcomeTone } from "@/lib/status";
import { EmptyState } from "./EmptyState";
import { ErrorState } from "./ErrorState";
import { LoadingRows } from "./LoadingRows";
import { StatusBadge } from "./StatusBadge";

/**
 * design.md's "Live feed": an append-only list with a pause control, new
 * rows entering without shifting focus. Purely presentational — no
 * EventSource, no fetch — so it is testable with plain props, the same
 * split useLiveFeed's own docblock explains.
 *
 * Rows already received take priority over a transient disconnect: losing
 * an operator's accumulated view because the browser's own EventSource is
 * mid-reconnect would be worse than a small connection indicator above an
 * otherwise-intact list.
 */
export function LiveFeed({
  state,
  connected,
  error,
  onPause,
  onResume,
}: {
  state: FeedState;
  connected: boolean;
  error: string | null;
  onPause: () => void;
  onResume: () => void;
}) {
  if (state.rows.length === 0) {
    if (error !== null) {
      return <ErrorState message={error} />;
    }

    if (!connected) {
      return <LoadingRows count={8} />;
    }

    return (
      <EmptyState
        title="No deliveries yet"
        hint="Send a test event from an endpoint's own screen to see it appear here."
      />
    );
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-xs text-text-subtle" aria-live="polite">
          {connected ? "Connected" : (error ?? "Reconnecting…")}
        </p>

        <button
          type="button"
          onClick={state.paused ? onResume : onPause}
          className="rounded-control border border-border px-3 py-1.5 text-sm text-text"
        >
          {state.paused ? `Paused — ${state.buffered.length} new` : "Pause"}
        </button>
      </div>

      <div className="overflow-hidden rounded-container border border-border">
        <table className="w-full text-sm">
          <thead className="bg-surface text-left text-text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">Outcome</th>
              <th className="px-4 py-2 font-medium">Event</th>
              <th className="px-4 py-2 font-medium">Endpoint</th>
              <th className="px-4 py-2 font-medium">Attempt</th>
              <th className="px-4 py-2 font-medium">Status</th>
              <th className="px-4 py-2 font-medium">Duration</th>
              <th className="px-4 py-2 font-medium">Time</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {state.rows.map((row) => {
              const outcome = attemptOutcomeTone(row.outcome);

              return (
                <tr key={row.id} className="hover:bg-surface">
                  <td className="px-4 py-2">
                    <StatusBadge tone={outcome.tone} label={outcome.label} />
                  </td>
                  <td className="px-4 py-2">
                    <Link href={`/messages/${row.message_id}`} className="font-medium text-accent">
                      {row.event_type}
                    </Link>
                  </td>
                  <td className="px-4 py-2 text-text-muted">{row.endpoint_name}</td>
                  <td className="px-4 py-2 font-tabular text-text-muted">#{row.attempt_number}</td>
                  <td className="px-4 py-2 font-tabular text-text-muted">{row.response_status ?? "—"}</td>
                  <td className="px-4 py-2 font-tabular text-text-muted">{formatDuration(row.duration_ms)}</td>
                  <td className="px-4 py-2 font-tabular text-text-muted">
                    {new Date(row.created_at).toLocaleTimeString()}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
