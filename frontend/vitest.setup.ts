import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

/**
 * vitest.config.ts runs without `globals: true`, so @testing-library/react's
 * own automatic cleanup — which registers itself through Vitest's global
 * `afterEach` — never fires, and a render() from one test stays mounted
 * into the next. Every test written through Step 13 avoided the problem
 * rather than solving it (a second case uses rerender() instead of a
 * second render(), or asserts on text unique to its own render); Step 14's
 * own multi-render component tests (ConfirmAction, AttemptTimeline,
 * PayloadInspector) are the first that genuinely need it.
 */
afterEach(() => {
  cleanup();
});
