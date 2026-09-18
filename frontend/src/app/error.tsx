"use client";

import { ErrorState } from "@/components/ErrorState";

export default function GlobalError({ error, reset }: { error: Error; reset: () => void }) {
  return (
    <html lang="en">
      <body className="flex min-h-screen items-center justify-center bg-bg p-6">
        <div className="w-full max-w-md">
          <ErrorState message={error.message} onRetry={reset} />
        </div>
      </body>
    </html>
  );
}
