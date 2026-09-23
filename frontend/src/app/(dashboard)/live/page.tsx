import { LiveFeedPanel } from "./LiveFeedPanel";

/**
 * The live delivery feed (Step 15 Phase B). A Server Component shell around
 * the one client component this app's conventions allow a direct backend
 * fetch of its own (conventions.md:67) — everything else here is static.
 */
export default function LivePage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-lg font-semibold text-text">Live</h1>
        <p className="mt-1 text-sm text-text-muted">
          Delivery attempts as they happen, roughly two seconds behind — the tail waits for a
          moment to be sure it has seen every attempt that landed in the same second.
        </p>
      </div>

      <LiveFeedPanel />
    </div>
  );
}
