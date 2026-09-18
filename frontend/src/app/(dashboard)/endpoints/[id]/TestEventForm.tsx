"use client";

import { useActionState } from "react";
import { StatusBadge } from "@/components/StatusBadge";
import { deliveryStatusTone } from "@/lib/status";
import { sendTestEventAction, type TestEventState } from "./actions";

const initialState: TestEventState = { status: "idle" };

/**
 * D76: the same ingest path a producer's own publish takes, narrowed to
 * this one endpoint. Landing on Step 14's message detail is still ahead of
 * this app — for now the receipt itself (message id, delivery id, the
 * delivery's status right after it was opened) is what proves the endpoint
 * was actually reached.
 */
export function TestEventForm({ endpointId, subscribedEventTypes }: { endpointId: string; subscribedEventTypes: string[] }) {
  const action = sendTestEventAction.bind(null, endpointId);
  const [state, formAction, pending] = useActionState(action, initialState);

  if (subscribedEventTypes.length === 0) {
    return <p className="text-sm text-text-muted">Subscribe this endpoint to an event type first.</p>;
  }

  return (
    <div className="space-y-3">
      <form action={formAction} className="flex items-center gap-2">
        <select
          name="event_type"
          className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
        >
          {subscribedEventTypes.map((name) => (
            <option key={name} value={name}>
              {name}
            </option>
          ))}
        </select>
        <button
          type="submit"
          disabled={pending}
          className="rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-fg disabled:opacity-60"
        >
          {pending ? "Sending…" : "Send test event"}
        </button>
      </form>

      {state.status === "error" ? <p className="text-sm text-danger-fg">{state.message}</p> : null}

      {state.status === "sent" ? (
        <div className="rounded-container border border-border bg-surface p-3 text-sm">
          <p className="font-tabular text-text-muted">
            {state.result.message.id} → {state.result.delivery_id}
          </p>
          <div className="mt-1">
            <StatusBadge {...deliveryStatusTone(state.result.delivery_status)} />
          </div>
        </div>
      ) : null}
    </div>
  );
}
