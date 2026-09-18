"use client";

import { useActionState } from "react";
import { syncSubscriptionsAction, type FormState } from "./actions";

const initialState: FormState = { error: null };

/**
 * A PUT, not a diff the client computes: every checkbox checked when this
 * submits is sent as the whole desired set, and SyncSubscriptions on the
 * backend decides what to add and remove. The client never guesses at the
 * add/remove split itself.
 */
export function SubscriptionsForm({
  endpointId,
  eventTypeNames,
  subscribed,
}: {
  endpointId: string;
  eventTypeNames: string[];
  subscribed: string[];
}) {
  const action = syncSubscriptionsAction.bind(null, endpointId);
  const [state, formAction, pending] = useActionState(action, initialState);

  return (
    <form action={formAction} className="space-y-3">
      {eventTypeNames.length === 0 ? (
        <p className="text-sm text-text-muted">
          No event types registered yet — create one from the Event types screen first.
        </p>
      ) : (
        <div className="flex flex-wrap gap-3">
          {eventTypeNames.map((name) => (
            <label key={name} className="flex items-center gap-2 text-sm text-text">
              <input
                type="checkbox"
                name="event_types"
                value={name}
                defaultChecked={subscribed.includes(name)}
                className="rounded border-border"
              />
              {name}
            </label>
          ))}
        </div>
      )}

      <button
        type="submit"
        disabled={pending}
        className="rounded-control border border-border px-3 py-1.5 text-sm text-text disabled:opacity-60"
      >
        {pending ? "Saving…" : "Save subscriptions"}
      </button>
      {state.error ? <p className="text-xs text-danger-fg">{state.error}</p> : null}
    </form>
  );
}
