"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/lib/api/errors";
import { createEventType } from "@/lib/api/server";

export type FormState = { error: string | null };

export async function createEventTypeAction(_previous: FormState, formData: FormData): Promise<FormState> {
  const name = String(formData.get("name") ?? "");

  try {
    await createEventType(name);
  } catch (error) {
    if (error instanceof ApiError) {
      return { error: error.fieldError("name") ?? error.message };
    }
    return { error: "Could not reach the server. Try again." };
  }

  revalidatePath("/event-types");
  return { error: null };
}
