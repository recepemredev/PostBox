"use client";

import { useActionState } from "react";
import { ConfirmAction } from "@/components/ConfirmAction";
import { replayMessageAction, type ReplayState } from "./actions";

const initialState: ReplayState = { status: "idle" };

/**
 * One component for both of D76/D122's message-scoped replay buttons — "to
 * this endpoint" (endpointId set) and "to every subscriber" (endpointId
 * null) are the same call with one field different, never two components.
 */
export function ReplayButton({
  messageId,
  endpointId,
  target,
}: {
  messageId: string;
  endpointId: string | null;
  target: string;
}) {
  const action = replayMessageAction.bind(null, messageId, endpointId);
  const [state, formAction, pending] = useActionState(action, initialState);

  if (state.status === "replayed") {
    const count = state.result.delivery_count;

    return (
      <span className="text-sm text-success-fg">
        Replayed — {count} new deliver{count === 1 ? "y" : "ies"} opened.
      </span>
    );
  }

  return (
    <ConfirmAction
      label={`Replay to ${target}`}
      description={`Send this message to ${target} again. This opens a new delivery — it never retries the row above.`}
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
