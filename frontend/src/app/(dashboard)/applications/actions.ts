"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/lib/api/errors";
import { createApplication } from "@/lib/api/server";

export type CreateApplicationState = {
  error: string | null;
};

export async function createApplicationAction(
  _previous: CreateApplicationState,
  formData: FormData,
): Promise<CreateApplicationState> {
  const name = String(formData.get("name") ?? "");

  try {
    await createApplication(name);
  } catch (error) {
    if (error instanceof ApiError) {
      return { error: error.fieldError("name") ?? error.message };
    }
    return { error: "Could not reach the server. Try again." };
  }

  revalidatePath("/applications");
  return { error: null };
}
