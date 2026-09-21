"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/lib/api/errors";
import { replayRange } from "@/lib/api/server";
import type { components } from "@/types/api";

export type RangeReplayState =
  | { status: "idle" }
  | { status: "replayed"; result: components["schemas"]["ReplayResource"] }
  | { status: "error"; message: string };

/**
 * Bound to the endpoint and the range the list screen's own filters already
 * name — canReplayRange() (lib/messages/filters.ts) is what the button
 * checked before this was ever callable, so a mismatched filter set never
 * reaches here in the first place.
 */
export async function replayRangeAction(
  endpointId: string,
  from: string,
  to: string,
  _previous: RangeReplayState,
): Promise<RangeReplayState> {
  try {
    const result = await replayRange(endpointId, from, to);
    revalidatePath("/messages");

    return { status: "replayed", result };
  } catch (error) {
    if (error instanceof ApiError) {
      return { status: "error", message: error.message };
    }

    return { status: "error", message: "Could not reach the server. Try again." };
  }
}
