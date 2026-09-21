import type { components } from "@/types/api";

type Attempt = components["schemas"]["DeliveryAttemptResource"];

/**
 * design.md: "Monospace, headers and body, as-sent and as-received.
 * Scrubbed headers are shown as redacted rather than omitted, so the
 * scrubbing itself is visible." Nothing here has to detect or mark a
 * scrubbed header specially — AttemptRecord::scrub() already replaced its
 * value with the literal `[redacted]` in storage (Step 14 Phase A), so
 * rendering every header verbatim already satisfies the rule.
 */
export function PayloadInspector({ attempt }: { attempt: Attempt }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="space-y-2">
        <h4 className="text-xs font-medium uppercase tracking-wide text-text-subtle">As sent</h4>
        <HeaderList headers={attempt.request_headers} />
        <BodyBlock body={attempt.request_body} />
      </div>

      <div className="space-y-2">
        <h4 className="text-xs font-medium uppercase tracking-wide text-text-subtle">As received</h4>
        {attempt.response_status !== null ? (
          <p className="font-tabular text-sm text-text">{attempt.response_status}</p>
        ) : (
          <p className="text-sm text-danger-fg">{attempt.error_message ?? "No response"}</p>
        )}
        {attempt.response_headers !== null ? <HeaderList headers={attempt.response_headers} /> : null}
        {attempt.response_body !== null ? <BodyBlock body={attempt.response_body} /> : null}
      </div>
    </div>
  );
}

function HeaderList({ headers }: { headers: { [key: string]: unknown } }) {
  const entries = Object.entries(headers);

  if (entries.length === 0) {
    return null;
  }

  return (
    <dl className="space-y-0.5 font-tabular text-xs">
      {entries.map(([name, value]) => (
        <div key={name} className="flex gap-2">
          <dt className="text-text-subtle">{name}:</dt>
          <dd className="break-all text-text">{String(value)}</dd>
        </div>
      ))}
    </dl>
  );
}

function BodyBlock({ body }: { body: string }) {
  if (body === "") {
    return null;
  }

  return <pre className="max-h-64 overflow-auto rounded-control bg-surface p-2 font-tabular text-xs text-text">{body}</pre>;
}
