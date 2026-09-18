/**
 * The loading state of design.md's four. Fixed row count rather than a
 * spinner: an operator scanning a list benefits from seeing the shape of
 * what is about to render, and the transition it replaces stays under the
 * 150ms design.md allows once real content lands.
 */
export function LoadingRows({ count = 4 }: { count?: number }) {
  return (
    <div className="space-y-2" aria-busy="true" aria-label="Loading">
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="h-10 animate-pulse rounded-control bg-surface" />
      ))}
    </div>
  );
}
