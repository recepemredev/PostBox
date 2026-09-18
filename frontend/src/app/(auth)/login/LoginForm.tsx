"use client";

import { useActionState } from "react";
import { loginAction, type LoginFormState } from "./actions";

const initialState: LoginFormState = { error: null };

export function LoginForm() {
  const [state, formAction, pending] = useActionState(loginAction, initialState);

  return (
    <form action={formAction} className="w-full max-w-sm space-y-4">
      <div>
        <label htmlFor="email" className="block text-sm font-medium text-text">
          Email
        </label>
        <input
          id="email"
          name="email"
          type="email"
          required
          autoComplete="email"
          className="mt-1 w-full rounded-control border border-border bg-surface-raised px-3 py-2 text-sm text-text"
        />
      </div>

      <div>
        <label htmlFor="password" className="block text-sm font-medium text-text">
          Password
        </label>
        <input
          id="password"
          name="password"
          type="password"
          required
          autoComplete="current-password"
          className="mt-1 w-full rounded-control border border-border bg-surface-raised px-3 py-2 text-sm text-text"
        />
      </div>

      {state.error ? <p className="text-sm text-danger-fg">{state.error}</p> : null}

      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-control bg-accent px-3 py-2 text-sm font-medium text-accent-fg disabled:opacity-60"
      >
        {pending ? "Signing in…" : "Sign in"}
      </button>
    </form>
  );
}
