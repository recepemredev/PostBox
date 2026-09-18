/**
 * Where the server side of this app reaches the backend. Never
 * `NEXT_PUBLIC_*`: a value under that prefix is bundled into client
 * JavaScript, and this one is only ever read from a Server Component,
 * Server Action or Route Handler running inside the `frontend` container —
 * `http://nginx:8080/api` there, never a URL a browser could resolve.
 *
 * A pure function so it can be tested without a request context: it takes
 * the env value as a parameter rather than reading `process.env` itself,
 * the same reason the rest of this module stays framework-free.
 */
export function buildApiUrl(baseUrl: string | undefined, path: string): string {
  if (!baseUrl) {
    throw new Error(
      "POSTBOX_SERVER_API_URL is not set — the server-side API client has no backend to reach.",
    );
  }

  const trimmedBase = baseUrl.replace(/\/+$/, "");
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;

  return `${trimmedBase}${normalizedPath}`;
}

export function apiBaseUrl(): string | undefined {
  return process.env.POSTBOX_SERVER_API_URL;
}
