import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { PayloadInspector } from "./PayloadInspector";
import type { components } from "@/types/api";

type Attempt = components["schemas"]["DeliveryAttemptResource"];

function attempt(overrides: Partial<Attempt> = {}): Attempt {
  return {
    id: "att_1",
    delivery_id: "dlv_1",
    endpoint_id: "ep_1",
    attempt_number: 1,
    outcome: "succeeded",
    request_headers: { "Content-Type": "application/json" },
    request_body: '{"total":4200}',
    response_status: 200,
    response_headers: { "Content-Type": "application/json" },
    response_body: '{"received":true}',
    error_message: null,
    duration_ms: 42,
    created_at: "2026-09-01T00:00:00Z",
    ...overrides,
  };
}

describe("PayloadInspector", () => {
  it("renders the request and response bodies verbatim", () => {
    render(<PayloadInspector attempt={attempt()} />);

    expect(screen.getByText('{"total":4200}')).toBeTruthy();
    expect(screen.getByText('{"received":true}')).toBeTruthy();
  });

  it("renders a scrubbed header's redaction marker as plain text, exactly as stored — nothing hides or re-labels it", () => {
    render(
      <PayloadInspector
        attempt={attempt({ response_headers: { "Set-Cookie": "[redacted]" } })}
      />,
    );

    expect(screen.getByText("[redacted]")).toBeTruthy();
  });

  it("shows the error message rather than a status when the attempt never received a response", () => {
    render(
      <PayloadInspector
        attempt={attempt({
          outcome: "timeout",
          response_status: null,
          response_headers: null,
          response_body: null,
          error_message: "The endpoint did not respond within the configured timeout.",
        })}
      />,
    );

    expect(screen.getByText("The endpoint did not respond within the configured timeout.")).toBeTruthy();
    expect(screen.queryByText("200")).toBeNull();
  });
});
