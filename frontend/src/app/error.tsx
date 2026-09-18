"use client";

import { ErrorState } from "@/components/ErrorState";

/**
 * A plain error.tsx, not global-error.tsx: it renders inside RootLayout,
 * which still owns <html> and <body> — a segment's own error boundary
 * never repeats them, only global-error.tsx (for a failure in the root
 * layout itself) is allowed to.
 */
export default function RootError({ error, reset }: { error: Error; reset: () => void }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-bg p-6">
      <div className="w-full max-w-md">
        <ErrorState message={error.message} onRetry={reset} />
      </div>
    </div>
  );
}
