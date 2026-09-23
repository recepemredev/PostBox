import type { operations } from "@/types/api";

/**
 * DescribeEventStream (backend) declares the stream's 200 response inline
 * on the operation rather than as a named component schema — Scramble has
 * no Resource class here to infer one from, since StreamController returns
 * a StreamedResponse. This is the one path to it openapi-typescript
 * generates; aliased once, here, rather than repeated at every import site.
 */
export type StreamedAttempt =
  operations["stream"]["responses"]["200"]["content"]["text/event-stream"];

/**
 * The live feed's own state — a pure reducer, the only place any of this
 * step's client-side logic lives (conventions.md: never inside a
 * component). useLiveFeed calls these functions; it does not reimplement
 * any of their decisions.
 */
export type FeedState = {
  /** Newest first. */
  rows: StreamedAttempt[];
  /** Arrived while paused; flushed into rows on resume(), newest first. */
  buffered: StreamedAttempt[];
  paused: boolean;
};

/**
 * An append-only list that never ends must still end somewhere — an
 * operator leaving the tab open overnight must not grow it until the tab
 * dies.
 */
export const MAX_ROWS = 200;

export function emptyFeed(): FeedState {
  return { rows: [], buffered: [], paused: false };
}

/**
 * One attempt arriving. Deduped by id: a reconnect's own resume contract
 * already rules this out server-side, but a reducer that cannot produce a
 * duplicate row is cheaper than trusting that, and design.md's "new rows
 * enter without shifting focus" depends on stable keys.
 */
export function receive(state: FeedState, attempt: StreamedAttempt): FeedState {
  if (state.paused) {
    if (state.buffered.some((existing) => existing.id === attempt.id)) {
      return state;
    }

    return { ...state, buffered: [attempt, ...state.buffered] };
  }

  if (state.rows.some((existing) => existing.id === attempt.id)) {
    return state;
  }

  return { ...state, rows: [attempt, ...state.rows].slice(0, MAX_ROWS) };
}

/**
 * Rows stop moving under the operator's own cursor (design.md's pause
 * control); anything that arrives while paused collects in buffered
 * instead of being dropped.
 */
export function pause(state: FeedState): FeedState {
  return { ...state, paused: true };
}

/** Flushes buffered into rows, newest first, in one go. */
export function resume(state: FeedState): FeedState {
  if (state.buffered.length === 0) {
    return { ...state, paused: false };
  }

  return {
    paused: false,
    buffered: [],
    rows: [...state.buffered, ...state.rows].slice(0, MAX_ROWS),
  };
}
