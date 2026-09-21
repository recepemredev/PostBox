"use client";

import { useState, type ReactNode } from "react";

/**
 * The two-step shell every destructive-looking action in this app uses —
 * roadmap Step 14's own "destructive-looking actions confirm with the count
 * first." One click arms it and shows what it is about to do; a second,
 * real click — a real button inside a real <form>, passed in as `confirm`
 * rather than a second onClick this component owns — is what actually
 * fires it. No confirm primitive existed before this; replay's own buttons
 * are its first use, and any later destructive action reuses this one
 * rather than inventing its own.
 */
export function ConfirmAction({
  label,
  description,
  confirm,
  disabled = false,
  disabledReason,
}: {
  label: string;
  description: string;
  confirm: ReactNode;
  disabled?: boolean;
  disabledReason?: string;
}) {
  const [armed, setArmed] = useState(false);

  if (disabled) {
    return (
      <div>
        <button
          type="button"
          disabled
          className="rounded-control border border-border px-3 py-1.5 text-sm text-text-subtle opacity-60"
        >
          {label}
        </button>
        {disabledReason ? <p className="mt-1 text-xs text-text-subtle">{disabledReason}</p> : null}
      </div>
    );
  }

  if (!armed) {
    return (
      <button
        type="button"
        onClick={() => setArmed(true)}
        className="rounded-control border border-border px-3 py-1.5 text-sm text-text"
      >
        {label}
      </button>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-container border border-warning-fg/30 bg-warning-bg p-3">
      <p className="text-sm text-warning-fg">{description}</p>
      {confirm}
      <button type="button" onClick={() => setArmed(false)} className="text-sm text-text-muted hover:text-text">
        Cancel
      </button>
    </div>
  );
}
