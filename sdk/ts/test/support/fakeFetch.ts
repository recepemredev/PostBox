/**
 * A fetch-compatible test double: answers with whatever was queued, in
 * order, and records every request PostBox.publish() actually sent — so a
 * test can assert the same Idempotency-Key travelled on every retry, or
 * that a non-retryable status never produced a second request at all.
 * Header names are recorded lower-cased, matching how the Fetch Headers
 * class itself normalises them.
 */
export interface RecordedRequest {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string;
}

export interface FakeFetch {
  fetch: typeof fetch;
  requests: RecordedRequest[];
  queueResponse(response: Response): void;
  queueError(error: Error): void;
}

export function createFakeFetch(): FakeFetch {
  const queue: (Response | Error)[] = [];
  const requests: RecordedRequest[] = [];

  const fetchFn = async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
    const url = typeof input === "string" ? input : input.toString();
    const headers: Record<string, string> = {};

    if (init?.headers) {
      new Headers(init.headers).forEach((value, name) => {
        headers[name] = value;
      });
    }

    requests.push({
      url,
      method: init?.method ?? "GET",
      headers,
      body: typeof init?.body === "string" ? init.body : "",
    });

    const next = queue.shift();

    if (next === undefined) {
      throw new Error("fakeFetch called more times than a response or error was queued.");
    }

    if (next instanceof Error) {
      throw next;
    }

    return next;
  };

  return {
    fetch: fetchFn as typeof fetch,
    requests,
    queueResponse: (response: Response): void => {
      queue.push(response);
    },
    queueError: (error: Error): void => {
      queue.push(error);
    },
  };
}

export function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...headers },
  });
}
