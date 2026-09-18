"use server";

import { redirect } from "next/navigation";
import { logout } from "@/lib/api/server";

export async function logoutAction(): Promise<void> {
  await logout();
  redirect("/login");
}
