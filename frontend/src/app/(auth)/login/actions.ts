"use server";

import { redirect } from "next/navigation";
import { ApiError } from "@/lib/api/errors";
import { login } from "@/lib/api/server";

export type LoginFormState = {
  error: string | null;
};

export async function loginAction(_previous: LoginFormState, formData: FormData): Promise<LoginFormState> {
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");

  try {
    await login({ email, password });
  } catch (error) {
    if (error instanceof ApiError) {
      // AuthenticateUser deliberately gives the same message for a wrong
      // password and an unknown address, on the same field — one error to
      // show, not two to distinguish.
      return { error: error.fieldError("email") ?? error.message };
    }

    return { error: "Could not reach the server. Try again." };
  }

  redirect("/applications");
}
