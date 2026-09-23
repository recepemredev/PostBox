"use client";

import { LiveFeed } from "@/components/LiveFeed";
import { useLiveFeed } from "@/lib/stream/useLiveFeed";

/**
 * The composition root: calls the one hook that owns the EventSource
 * connection and hands its state straight to the presentational component.
 * Kept out of page.tsx because a Server Component file cannot itself carry
 * "use client" — nothing here is business logic worth its own test, since
 * every decision either component it wires together already makes and
 * tests on its own.
 */
export function LiveFeedPanel() {
  const { state, connected, error, pause, resume } = useLiveFeed();

  return <LiveFeed state={state} connected={connected} error={error} onPause={pause} onResume={resume} />;
}
