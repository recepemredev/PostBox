"use client";

import { useEffect, useState } from "react";
import { emptyFeed, pause as pauseFeed, receive, resume as resumeFeed, type FeedState, type StreamedAttempt } from "./feed";

/**
 * The project's first useEffect — connection management only, and nothing
 * else: every state transition it can produce goes through feed.ts's own
 * pure functions (conventions.md: never put business logic inside a
 * component). Deliberately untested — jsdom has no EventSource, and every
 * decision this hook could get wrong already lives in feed.ts's own, fully
 * tested reducer instead. The same "thin, untested layer" choice
 * route-guard.ts's own docblock already makes for middleware.ts.
 */
export function useLiveFeed(): {
  state: FeedState;
  connected: boolean;
  error: string | null;
  pause: () => void;
  resume: () => void;
} {
  const [state, setState] = useState<FeedState>(emptyFeed);
  const [connected, setConnected] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // A relative, same-origin path, never apiBaseUrl() — that resolves to
    // a container-only address no browser can reach. This is the one
    // client-side fetch to the backend this app is allowed
    // (conventions.md:67); the session cookie rides along automatically on
    // a same-origin request, and the browser's own EventSource — not this
    // hook — is what resends Last-Event-ID on every reconnect.
    const source = new EventSource("/api/v1/stream");

    const handleOpen = () => {
      setConnected(true);
      setError(null);
    };

    const handleError = () => {
      setConnected(false);
      setError("Disconnected from the live feed.");
    };

    const handleAttempt = (event: MessageEvent<string>) => {
      const attempt = JSON.parse(event.data) as StreamedAttempt;
      setState((previous) => receive(previous, attempt));
    };

    source.addEventListener("open", handleOpen);
    source.addEventListener("error", handleError);
    source.addEventListener("attempt", handleAttempt);

    return () => {
      source.close();
    };
  }, []);

  return {
    state,
    connected,
    error,
    pause: () => setState((previous) => pauseFeed(previous)),
    resume: () => setState((previous) => resumeFeed(previous)),
  };
}
