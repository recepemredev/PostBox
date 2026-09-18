import Link from "next/link";

/**
 * A foreign tenant's application, endpoint or secret is a 404 here, not a
 * 403 — route model binding on the backend already draws that line
 * (architecture.md), and this is the one screen every such 404 lands on.
 */
export default function NotFound() {
  return (
    <div className="rounded-container border border-border bg-surface px-6 py-8 text-center">
      <p className="text-sm font-medium text-text">Not found</p>
      <p className="mt-1 text-sm text-text-muted">
        This does not exist, or it belongs to a different tenant.
      </p>
      <Link href="/applications" className="mt-4 inline-block text-sm text-accent">
        ← Back to applications
      </Link>
    </div>
  );
}
