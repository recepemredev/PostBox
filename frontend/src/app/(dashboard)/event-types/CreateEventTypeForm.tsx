"use client";

import { useActionState } from "react";
import { createEventTypeAction, type FormState } from "./actions";

const initialState: FormState = { error: null };

export function CreateEventTypeForm() {
  const [state, formAction, pending] = useActionState(createEventTypeAction, initialState);

  return (
    <form action={formAction} className="flex items-start gap-2">
      <div>
        <input
          name="name"
          required
          placeholder="invoice.paid"
          className="rounded-control border border-border bg-surface-raised px-3 py-1.5 font-tabular text-sm text-text"
        />
        {state.error ? <p className="mt-1 text-xs text-danger-fg">{state.error}</p> : null}
      </div>
      <button
        type="submit"
        disabled={pending}
        className="rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-fg disabled:opacity-60"
      >
        {pending ? "Registering…" : "Register event type"}
      </button>
    </form>
  );
}
