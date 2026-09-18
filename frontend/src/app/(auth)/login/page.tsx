import { LoginForm } from "./LoginForm";

/**
 * No sign-up, no password reset, no "remember me" (D29) — a tenant is
 * created from the console (`postbox:tenant`), never from this screen.
 */
export default function LoginPage() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-bg px-4">
      <div className="w-full max-w-sm">
        <h1 className="mb-6 text-center text-lg font-semibold text-text">PostBox</h1>
        <LoginForm />
      </div>
    </main>
  );
}
