"use client";

import { useActionState } from "react";
import { ConfirmAction } from "@/components/ConfirmAction";
import { replayRangeAction, type RangeReplayState } from "./actions";

const initialState: RangeReplayState = { status: "idle" };

/**
 * Active only when the screen's own filters already map onto what range
 * replay can express (canReplayRange, D122) — disabled otherwise, with a
 * written reason, rather than always enabled with a dialog explaining away
 * a mismatch after the fact.
 */
export function RangeReplayButton({
  endpoint,
  from,
  to,
  canReplay,
}: {
  endpoint: string | null;
  from: string | null;
  to: string | null;
  canReplay: boolean;
}) {
  const action = replayRangeAction.bind(null, endpoint ?? "", from ?? "", to ?? "");
  const [state, formAction, pending] = useActionState(action, initialState);

  if (state.status === "replayed") {
    const count = state.result.delivery_count;

    return (
      <p className="text-sm text-success-fg">
        Replayed — {count} new deliver{count === 1 ? "y" : "ies"} opened
        {state.result.next_cursor !== undefined && state.result.next_cursor !== null
          ? ", more remain (run it again to continue)."
          : "."}
      </p>
    );
  }

  return (
    <ConfirmAction
      label="Replay this range"
      description="Replays this endpoint's own failed deliveries in the selected time range, up to 200 at a time. Each one opens a new delivery — it never retries the row it came from."
      disabled={!canReplay}
      disabledReason="Select one endpoint, set status to Failed, and choose a time range to enable range replay."
      confirm={
        <form action={formAction} className="flex items-center gap-2">
          <button
            type="submit"
            disabled={pending}
            className="rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-fg disabled:opacity-60"
          >
            {pending ? "Replaying…" : "Confirm replay"}
          </button>
          {state.status === "error" ? <span className="text-xs text-danger-fg">{state.message}</span> : null}
        </form>
      }
    />
  );
}
