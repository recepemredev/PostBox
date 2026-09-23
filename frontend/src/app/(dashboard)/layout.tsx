import Link from "next/link";
import { redirect } from "next/navigation";
import { ApiError } from "@/lib/api/errors";
import { getIdentity } from "@/lib/identity";
import { logoutAction } from "./actions";

const NAV_ITEMS = [
  { href: "/applications", label: "Applications" },
  { href: "/messages", label: "Messages" },
  { href: "/live", label: "Live" },
  { href: "/event-types", label: "Event types" },
];

/**
 * middleware.ts's own cookie check is only a fast path; this is the real
 * one. A stale cookie whose session has since expired answers 401 here,
 * and the redirect happens exactly where the backend's own answer says it
 * should — never assumed from the cookie's mere presence.
 */
export default async function DashboardLayout({ children }: { children: React.ReactNode }) {
  let identity;

  try {
    identity = await getIdentity();
  } catch (error) {
    if (error instanceof ApiError && error.isUnauthenticated) {
      redirect("/login");
    }
    throw error;
  }

  return (
    <div className="min-h-screen bg-bg">
      <header className="border-b border-border bg-surface-raised">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-3">
          <div className="flex items-center gap-6">
            <span className="text-sm font-semibold text-text">PostBox</span>
            <nav className="flex gap-4">
              {NAV_ITEMS.map((item) => (
                <Link key={item.href} href={item.href} className="text-sm text-text-muted hover:text-text">
                  {item.label}
                </Link>
              ))}
            </nav>
          </div>

          <div className="flex items-center gap-4 text-sm text-text-muted">
            <span>
              {identity.tenant.name} · {identity.user.email}
            </span>
            <form action={logoutAction}>
              <button type="submit" className="text-text-muted hover:text-text">
                Sign out
              </button>
            </form>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-6 py-8">{children}</main>
    </div>
  );
}
