"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/lib/api/errors";
import { createEndpoint, renameApplication } from "@/lib/api/server";

export type FormState = { error: string | null };

export async function renameApplicationAction(
  applicationId: string,
  _previous: FormState,
  formData: FormData,
): Promise<FormState> {
  const name = String(formData.get("name") ?? "");

  try {
    await renameApplication(applicationId, name);
  } catch (error) {
    if (error instanceof ApiError) {
      return { error: error.fieldError("name") ?? error.message };
    }
    return { error: "Could not reach the server. Try again." };
  }

  revalidatePath(`/applications/${applicationId}`);
  return { error: null };
}

export async function createEndpointAction(
  applicationId: string,
  _previous: FormState,
  formData: FormData,
): Promise<FormState> {
  const name = String(formData.get("name") ?? "");
  const url = String(formData.get("url") ?? "");

  try {
    await createEndpoint(applicationId, { name, url });
  } catch (error) {
    if (error instanceof ApiError) {
      return { error: error.fieldError("url") ?? error.fieldError("name") ?? error.message };
    }
    return { error: "Could not reach the server. Try again." };
  }

  revalidatePath(`/applications/${applicationId}`);
  return { error: null };
}
