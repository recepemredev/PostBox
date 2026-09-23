import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { emptyFeed, pause, receive, type StreamedAttempt } from "@/lib/stream/feed";
import { LiveFeed } from "./LiveFeed";

function attempt(id: string): StreamedAttempt {
  return {
    id,
    delivery_id: "dlv_1",
    message_id: "msg_1",
    endpoint_id: "ep_1",
    endpoint_name: "Orders webhook",
    event_type: "invoice.paid",
    attempt_number: 1,
    outcome: "failed",
    response_status: 500,
    duration_ms: 120,
    delivery_status: "pending",
    created_at: "2026-09-23T00:00:00Z",
  };
}

describe("LiveFeed", () => {
  it("shows a loading state while connecting, before the first frame", () => {
    render(<LiveFeed state={emptyFeed()} connected={false} error={null} onPause={() => {}} onResume={() => {}} />);

    expect(screen.getByLabelText("Loading")).toBeTruthy();
  });

  it("shows an empty state once connected with nothing received yet, naming the next action", () => {
    render(<LiveFeed state={emptyFeed()} connected error={null} onPause={() => {}} onResume={() => {}} />);

    expect(screen.getByText("No deliveries yet")).toBeTruthy();
    expect(screen.getByText(/Send a test event/)).toBeTruthy();
  });

  it("shows an error state when disconnected with nothing received yet", () => {
    render(
      <LiveFeed
        state={emptyFeed()}
        connected={false}
        error="Disconnected from the live feed."
        onPause={() => {}}
        onResume={() => {}}
      />,
    );

    expect(screen.getByText("Disconnected from the live feed.")).toBeTruthy();
  });

  it("renders a received attempt with its outcome as visible text, not colour alone", () => {
    const state = receive(emptyFeed(), attempt("att_1"));

    render(<LiveFeed state={state} connected error={null} onPause={() => {}} onResume={() => {}} />);

    expect(screen.getByText("Failed")).toBeTruthy();
    expect(screen.getByText("invoice.paid")).toBeTruthy();
    expect(screen.getByText("Orders webhook")).toBeTruthy();
  });

  it("keeps showing already-received rows through a disconnect, rather than blanking them", () => {
    const state = receive(emptyFeed(), attempt("att_1"));

    render(
      <LiveFeed
        state={state}
        connected={false}
        error="Disconnected from the live feed."
        onPause={() => {}}
        onResume={() => {}}
      />,
    );

    expect(screen.getByText("invoice.paid")).toBeTruthy();
    expect(screen.getByText("Disconnected from the live feed.")).toBeTruthy();
  });

  it("while paused, shows the buffered count and calls onResume, not onPause, when clicked", () => {
    let resumed = false;
    const state = pause(receive(emptyFeed(), attempt("att_1")));
    const withBuffered = receive(state, attempt("att_2"));

    render(
      <LiveFeed
        state={withBuffered}
        connected
        error={null}
        onPause={() => {
          throw new Error("onPause should not be called while paused");
        }}
        onResume={() => {
          resumed = true;
        }}
      />,
    );

    const button = screen.getByText("Paused — 1 new");
    fireEvent.click(button);

    expect(resumed).toBe(true);
  });

  it("calls onPause when the pause button is clicked while running", () => {
    let paused = false;
    const state = receive(emptyFeed(), attempt("att_1"));

    render(
      <LiveFeed
        state={state}
        connected
        error={null}
        onPause={() => {
          paused = true;
        }}
        onResume={() => {}}
      />,
    );

    fireEvent.click(screen.getByText("Pause"));

    expect(paused).toBe(true);
  });
});
