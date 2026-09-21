import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { AttemptTimeline } from "./AttemptTimeline";
import type { components } from "@/types/api";

type Attempt = components["schemas"]["DeliveryAttemptResource"];

function attempt(overrides: Partial<Attempt> = {}): Attempt {
  return {
    id: "att_1",
    delivery_id: "dlv_1",
    endpoint_id: "ep_1",
    attempt_number: 1,
    outcome: "succeeded",
    request_headers: {},
    request_body: "{}",
    response_status: 200,
    response_headers: {},
    response_body: "ok",
    error_message: null,
    duration_ms: 42,
    created_at: "2026-09-01T00:00:00Z",
    ...overrides,
  };
}

describe("AttemptTimeline", () => {
  it("explains rather than renders an empty track when there are no attempts yet", () => {
    render(<AttemptTimeline attempts={[]} nextAttemptAt={null} />);

    expect(screen.getByText("No attempts yet.")).toBeTruthy();
  });

  it("renders one node per attempt, labelled with its own outcome", () => {
    render(
      <AttemptTimeline
        attempts={[
          attempt({ id: "att_1", attempt_number: 1, outcome: "failed", created_at: "2026-09-01T00:00:00Z" }),
          attempt({ id: "att_2", attempt_number: 2, outcome: "succeeded", created_at: "2026-09-01T00:00:05Z" }),
        ]}
        nextAttemptAt={null}
      />,
    );

    expect(screen.getByText("#1")).toBeTruthy();
    expect(screen.getByText("#2")).toBeTruthy();
    expect(screen.getByText("Failed")).toBeTruthy();
    expect(screen.getByText("Succeeded")).toBeTruthy();
  });

  it("shows the delay between two attempts that already happened, not a next-attempt indicator", () => {
    render(
      <AttemptTimeline
        attempts={[
          attempt({ id: "att_1", attempt_number: 1, created_at: "2026-09-01T00:00:00Z" }),
          attempt({ id: "att_2", attempt_number: 2, created_at: "2026-09-01T00:00:05Z" }),
        ]}
        nextAttemptAt={null}
      />,
    );

    expect(screen.getByText("5s →")).toBeTruthy();
    expect(screen.queryByText(/next at/)).toBeNull();
  });

  it("shows when the next attempt is due after the last attempt actually made", () => {
    render(
      <AttemptTimeline
        attempts={[attempt({ id: "att_1", attempt_number: 1, created_at: "2026-09-01T00:00:00Z" })]}
        nextAttemptAt="2026-09-01T00:05:00Z"
      />,
    );

    expect(screen.getByText(/next at/)).toBeTruthy();
  });
});
