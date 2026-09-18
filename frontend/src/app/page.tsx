import { redirect } from "next/navigation";

/**
 * Every path but /login requires a session by the time middleware lets it
 * through, so "/" itself needs no dashboard of its own — it lands on the
 * screen an operator actually starts from.
 */
export default function Home() {
  redirect("/applications");
}
