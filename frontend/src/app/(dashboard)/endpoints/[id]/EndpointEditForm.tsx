"use client";

import { useActionState } from "react";
import { updateEndpointAction, type FormState } from "./actions";

const initialState: FormState = { error: null };

export function EndpointEditForm({
  endpointId,
  name,
  url,
  status,
}: {
  endpointId: string;
  name: string;
  url: string;
  status: string;
}) {
  const action = updateEndpointAction.bind(null, endpointId);
  const [state, formAction, pending] = useActionState(action, initialState);

  return (
    <form action={formAction} className="grid gap-3 sm:grid-cols-[1fr_2fr_auto_auto]">
      <input
        name="name"
        defaultValue={name}
        required
        className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
      />
      <input
        name="url"
        type="url"
        defaultValue={url}
        required
        className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
      />
      <select
        name="status"
        defaultValue={status}
        className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
      >
        <option value="enabled">Enabled</option>
        <option value="disabled">Disabled</option>
      </select>
      <button
        type="submit"
        disabled={pending}
        className="rounded-control border border-border px-3 py-1.5 text-sm text-text disabled:opacity-60"
      >
        {pending ? "Saving…" : "Save"}
      </button>
      {state.error ? <p className="col-span-full text-xs text-danger-fg">{state.error}</p> : null}
    </form>
  );
}
