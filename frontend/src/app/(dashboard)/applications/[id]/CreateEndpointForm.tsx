"use client";

import { useActionState } from "react";
import { createEndpointAction, type FormState } from "./actions";

const initialState: FormState = { error: null };

export function CreateEndpointForm({ applicationId }: { applicationId: string }) {
  const action = createEndpointAction.bind(null, applicationId);
  const [state, formAction, pending] = useActionState(action, initialState);

  return (
    <form action={formAction} className="flex flex-wrap items-start gap-2">
      <input
        name="name"
        required
        placeholder="Endpoint name"
        className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
      />
      <input
        name="url"
        required
        type="url"
        placeholder="https://example.test/webhook"
        className="min-w-64 rounded-control border border-border bg-surface-raised px-3 py-1.5 text-sm text-text"
      />
      <button
        type="submit"
        disabled={pending}
        className="rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-fg disabled:opacity-60"
      >
        {pending ? "Creating…" : "New endpoint"}
      </button>
      {state.error ? <p className="w-full text-xs text-danger-fg">{state.error}</p> : null}
    </form>
  );
}
