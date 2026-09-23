import { describe, expect, it } from "vitest";
import { MAX_ROWS, emptyFeed, pause, receive, resume, type StreamedAttempt } from "./feed";

function attempt(id: string): StreamedAttempt {
  return {
    id,
    delivery_id: "dlv_1",
    message_id: "msg_1",
    endpoint_id: "ep_1",
    endpoint_name: "Test endpoint",
    event_type: "invoice.paid",
    attempt_number: 1,
    outcome: "succeeded",
    response_status: 200,
    duration_ms: 42,
    delivery_status: "succeeded",
    created_at: "2026-09-23T00:00:00Z",
  };
}

describe("receive", () => {
  it("prepends, newest first", () => {
    const state = receive(receive(emptyFeed(), attempt("att_1")), attempt("att_2"));

    expect(state.rows.map((row) => row.id)).toEqual(["att_2", "att_1"]);
  });

  it("the same id twice yields one row", () => {
    const state = receive(receive(emptyFeed(), attempt("att_1")), attempt("att_1"));

    expect(state.rows).toHaveLength(1);
  });

  it("enforces MAX_ROWS, dropping the oldest", () => {
    let state = emptyFeed();

    for (let i = 0; i < MAX_ROWS + 5; i++) {
      state = receive(state, attempt(`att_${i}`));
    }

    expect(state.rows).toHaveLength(MAX_ROWS);
    expect(state.rows[0]?.id).toBe(`att_${MAX_ROWS + 4}`);
    expect(state.rows.at(-1)?.id).toBe(`att_${5}`);
  });
});

describe("pause and resume", () => {
  it("while paused, rows does not change and buffered grows", () => {
    const paused = pause(receive(emptyFeed(), attempt("att_1")));
    const afterOne = receive(paused, attempt("att_2"));
    const afterTwo = receive(afterOne, attempt("att_3"));

    expect(afterTwo.rows.map((row) => row.id)).toEqual(["att_1"]);
    expect(afterTwo.buffered.map((row) => row.id)).toEqual(["att_3", "att_2"]);
  });

  it("resume() flushes buffered into rows, newest first, and clears buffered", () => {
    let state = pause(receive(emptyFeed(), attempt("att_1")));
    state = receive(state, attempt("att_2"));
    state = receive(state, attempt("att_3"));

    const resumed = resume(state);

    expect(resumed.paused).toBe(false);
    expect(resumed.buffered).toEqual([]);
    expect(resumed.rows.map((row) => row.id)).toEqual(["att_3", "att_2", "att_1"]);
  });

  it("resuming an empty buffer is a no-op beyond clearing paused", () => {
    const state = pause(receive(emptyFeed(), attempt("att_1")));

    const resumed = resume(state);

    expect(resumed.paused).toBe(false);
    expect(resumed.rows.map((row) => row.id)).toEqual(["att_1"]);
  });

  it("a duplicate id arriving while paused does not double up in buffered", () => {
    const paused = pause(receive(emptyFeed(), attempt("att_1")));
    const state = receive(receive(paused, attempt("att_2")), attempt("att_2"));

    expect(state.buffered).toHaveLength(1);
  });
});
