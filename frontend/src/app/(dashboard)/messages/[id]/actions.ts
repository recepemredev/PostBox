"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/lib/api/errors";
import { replayMessage } from "@/lib/api/server";
import type { components } from "@/types/api";

export type ReplayState =
  | { status: "idle" }
  | { status: "replayed"; result: components["schemas"]["ReplayResource"] }
  | { status: "error"; message: string };

/**
 * D76/D122: "retry this delivery" and "replay to every subscriber" are the
 * same action, endpointId present or absent — never two near-identical
 * Server Actions for one request shape.
 */
export async function replayMessageAction(
  messageId: string,
  endpointId: string | null,
  _previous: ReplayState,
): Promise<ReplayState> {
  try {
    const result = await replayMessage(messageId, endpointId);
    revalidatePath(`/messages/${messageId}`);

    return { status: "replayed", result };
  } catch (error) {
    if (error instanceof ApiError) {
      return { status: "error", message: error.message };
    }

    return { status: "error", message: "Could not reach the server. Try again." };
  }
}
