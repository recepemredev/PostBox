"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/lib/api/errors";
import {
  issueSecret,
  revokeSecret,
  sendTestEvent,
  syncSubscriptions,
  updateEndpoint,
} from "@/lib/api/server";
import type { components } from "@/types/api";

export type FormState = { error: string | null };

export async function updateEndpointAction(
  endpointId: string,
  _previous: FormState,
  formData: FormData,
): Promise<FormState> {
  const changes: components["schemas"]["UpdateEndpointRequest"] = {
    name: String(formData.get("name") ?? ""),
    url: String(formData.get("url") ?? ""),
    status: formData.get("status") === "disabled" ? "disabled" : "enabled",
  };

  try {
    await updateEndpoint(endpointId, changes);
  } catch (error) {
    if (error instanceof ApiError) {
      return { error: error.fieldError("url") ?? error.fieldError("name") ?? error.message };
    }
    return { error: "Could not reach the server. Try again." };
  }

  revalidatePath(`/endpoints/${endpointId}`);
  return { error: null };
}

export async function syncSubscriptionsAction(
  endpointId: string,
  _previous: FormState,
  formData: FormData,
): Promise<FormState> {
  const eventTypes = formData.getAll("event_types").map(String);

  try {
    await syncSubscriptions(endpointId, eventTypes);
  } catch (error) {
    if (error instanceof ApiError) {
      return { error: error.message };
    }
    return { error: "Could not reach the server. Try again." };
  }

  revalidatePath(`/endpoints/${endpointId}`);
  return { error: null };
}

export type IssueSecretState =
  | { status: "idle" }
  | { status: "issued"; secret: components["schemas"]["IssuedEndpointSecretResource"] }
  | { status: "error"; message: string };

export async function issueSecretAction(endpointId: string): Promise<IssueSecretState> {
  try {
    const secret = await issueSecret(endpointId);
    revalidatePath(`/endpoints/${endpointId}`);
    return { status: "issued", secret };
  } catch (error) {
    if (error instanceof ApiError) {
      return { status: "error", message: error.message };
    }
    return { status: "error", message: "Could not reach the server. Try again." };
  }
}

export async function revokeSecretAction(endpointId: string, secretId: string): Promise<void> {
  await revokeSecret(endpointId, secretId);
  revalidatePath(`/endpoints/${endpointId}`);
}

export type TestEventState =
  | { status: "idle" }
  | { status: "sent"; result: components["schemas"]["TestEventResource"] }
  | { status: "error"; message: string };

export async function sendTestEventAction(
  endpointId: string,
  _previous: TestEventState,
  formData: FormData,
): Promise<TestEventState> {
  const eventType = String(formData.get("event_type") ?? "");

  try {
    const result = await sendTestEvent(endpointId, eventType);
    return { status: "sent", result };
  } catch (error) {
    if (error instanceof ApiError) {
      return { status: "error", message: error.fieldError("event_type") ?? error.message };
    }
    return { status: "error", message: "Could not reach the server. Try again." };
  }
}
