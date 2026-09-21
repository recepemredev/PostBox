import { describe, expect, it } from "vitest";
import { canReplayRange, parseMessageFilters, toQueryString } from "./filters";

describe("parseMessageFilters", () => {
  it("reads every filter from a plain searchParams object", () => {
    const filters = parseMessageFilters({
      endpoint: "ep_123",
      event_type: "invoice.paid",
      status: "exhausted",
      from: "2026-09-01T00:00",
      to: "2026-09-02T00:00",
      cursor: "abc",
      limit: "25",
    });

    expect(filters).toEqual({
      endpoint: "ep_123",
      eventType: "invoice.paid",
      status: "exhausted",
      from: "2026-09-01T00:00",
      to: "2026-09-02T00:00",
      cursor: "abc",
      limit: 25,
    });
  });

  it("defaults every missing filter to null", () => {
    expect(parseMessageFilters({})).toEqual({
      endpoint: null,
      eventType: null,
      status: null,
      from: null,
      to: null,
      cursor: null,
      limit: null,
    });
  });

  it("treats an empty string the same as an absent value", () => {
    expect(parseMessageFilters({ endpoint: "" }).endpoint).toBeNull();
  });

  it("takes the first value when a param repeats", () => {
    expect(parseMessageFilters({ endpoint: ["ep_1", "ep_2"] }).endpoint).toBe("ep_1");
  });

  it("drops a status the backend's own enum does not name, rather than passing it through", () => {
    expect(parseMessageFilters({ status: "not-a-real-status" }).status).toBeNull();
  });

  it("drops a limit that is not a positive integer", () => {
    expect(parseMessageFilters({ limit: "0" }).limit).toBeNull();
    expect(parseMessageFilters({ limit: "-5" }).limit).toBeNull();
    expect(parseMessageFilters({ limit: "not-a-number" }).limit).toBeNull();
  });
});

describe("toQueryString", () => {
  it("builds an empty string when nothing is set", () => {
    expect(
      toQueryString({ endpoint: null, eventType: null, status: null, from: null, to: null, cursor: null, limit: null }),
    ).toBe("");
  });

  it("includes only the filters that are set", () => {
    const query = toQueryString({
      endpoint: "ep_123",
      eventType: null,
      status: "exhausted",
      from: null,
      to: null,
      cursor: null,
      limit: null,
    });

    expect(query).toBe("?endpoint=ep_123&status=exhausted");
  });

  it("carries the cursor and limit alongside every other filter", () => {
    const query = toQueryString({
      endpoint: null,
      eventType: null,
      status: null,
      from: null,
      to: null,
      cursor: "next-token",
      limit: 25,
    });

    expect(query).toBe("?cursor=next-token&limit=25");
  });
});

describe("canReplayRange", () => {
  it("is true only when the filters map exactly onto what range replay can express", () => {
    expect(
      canReplayRange({
        endpoint: "ep_123",
        eventType: null,
        status: "exhausted",
        from: "2026-09-01T00:00",
        to: "2026-09-02T00:00",
        cursor: null,
        limit: null,
      }),
    ).toBe(true);
  });

  it("is false without an endpoint", () => {
    expect(
      canReplayRange({
        endpoint: null,
        eventType: null,
        status: "exhausted",
        from: "2026-09-01T00:00",
        to: "2026-09-02T00:00",
        cursor: null,
        limit: null,
      }),
    ).toBe(false);
  });

  it("is false when status is not exhausted", () => {
    expect(
      canReplayRange({
        endpoint: "ep_123",
        eventType: null,
        status: "pending",
        from: "2026-09-01T00:00",
        to: "2026-09-02T00:00",
        cursor: null,
        limit: null,
      }),
    ).toBe(false);
  });

  it("is false without both ends of a time range", () => {
    expect(
      canReplayRange({
        endpoint: "ep_123",
        eventType: null,
        status: "exhausted",
        from: "2026-09-01T00:00",
        to: null,
        cursor: null,
        limit: null,
      }),
    ).toBe(false);
  });
});
