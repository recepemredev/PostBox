/**
 * Placeholder shell. The operator dashboard — layout, tenant context and design
 * tokens — arrives in Step 13; this page exists so the container has something to
 * serve and the ingress path can be verified end to end.
 */
export default function Home() {
  return (
    <main className="mx-auto flex min-h-screen max-w-2xl flex-col justify-center gap-4 px-6">
      <h1 className="text-2xl font-semibold">PostBox</h1>
      <p className="text-sm">
        Multi-tenant webhook delivery gateway. The operator dashboard is not built
        yet — Step 1 of 17 covers the foundation and the quality gates.
      </p>
      <p className="text-sm">
        <a className="underline" href="/api/health">
          /api/health
        </a>{" "}
        reports database, Redis, queue and migration state.
      </p>
    </main>
  );
}
