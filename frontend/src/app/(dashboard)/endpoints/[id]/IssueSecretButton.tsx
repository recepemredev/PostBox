"use client";

import { useActionState, useState } from "react";
import { issueSecretAction, type IssueSecretState } from "./actions";

const initialState: IssueSecretState = { status: "idle" };

/**
 * The one place a secret's plaintext ever exists in this app: held in
 * client state from the action's own return value, never re-fetched, never
 * written anywhere else. It is gone the moment this component unmounts —
 * navigating away and back shows only EndpointSecretResource's own fields.
 */
export function IssueSecretButton({ endpointId }: { endpointId: string }) {
  const action = issueSecretAction.bind(null, endpointId);
  const [state, formAction, pending] = useActionState(action, initialState);
  const [copied, setCopied] = useState(false);

  if (state.status === "issued") {
    return (
      <div className="rounded-container border border-warning-fg/30 bg-warning-bg p-4">
        <p className="text-sm font-medium text-warning-fg">
          Shown once — copy it now. It will not be shown again.
        </p>
        <div className="mt-2 flex items-center gap-2">
          <code className="flex-1 overflow-x-auto rounded-control bg-surface-raised px-3 py-1.5 font-tabular text-sm text-text">
            {state.secret.secret}
          </code>
          <button
            type="button"
            onClick={() => {
              void navigator.clipboard.writeText(state.secret.secret);
              setCopied(true);
            }}
            className="rounded-control border border-border px-3 py-1.5 text-sm text-text"
          >
            {copied ? "Copied" : "Copy"}
          </button>
        </div>
      </div>
    );
  }

  return (
    <form action={formAction}>
      <button
        type="submit"
        disabled={pending}
        className="rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-fg disabled:opacity-60"
      >
        {pending ? "Issuing…" : "Issue new secret"}
      </button>
      {state.status === "error" ? <p className="mt-1 text-xs text-danger-fg">{state.message}</p> : null}
    </form>
  );
}
