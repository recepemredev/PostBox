"use client";

import { useActionState } from "react";
import { renameApplicationAction, type FormState } from "./actions";

const initialState: FormState = { error: null };

export function RenameApplicationForm({ applicationId, name }: { applicationId: string; name: string }) {
  const action = renameApplicationAction.bind(null, applicationId);
  const [state, formAction, pending] = useActionState(action, initialState);

  return (
    <form action={formAction} className="flex items-center gap-2">
      <input
        name="name"
        defaultValue={name}
        required
        className="rounded-control border border-border bg-surface-raised px-3 py-1.5 text-lg font-semibold text-text"
      />
      <button
        type="submit"
        disabled={pending}
        className="rounded-control border border-border px-3 py-1.5 text-sm text-text disabled:opacity-60"
      >
        {pending ? "Saving…" : "Rename"}
      </button>
      {state.error ? <span className="text-xs text-danger-fg">{state.error}</span> : null}
    </form>
  );
}
