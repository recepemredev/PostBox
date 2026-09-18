/**
 * The fourth of design.md's four required states. A route's own error.tsx
 * renders this for an unhandled failure; a page that catches a specific,
 * expected error (403, 404) renders it directly instead of throwing.
 */
export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="rounded-container border border-danger-fg/30 bg-danger-bg px-6 py-8 text-center">
      <p className="text-sm font-medium text-danger-fg">Something went wrong</p>
      <p className="mt-1 text-sm text-danger-fg/80">{message}</p>
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="mt-4 rounded-control bg-danger-fg px-3 py-1.5 text-sm font-medium text-white"
        >
          Try again
        </button>
      ) : null}
    </div>
  );
}
