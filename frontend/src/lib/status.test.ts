import { describe, expect, it } from "vitest";
import { breakerStateTone, deliveryStatusTone, endpointStatusTone } from "./status";

describe("deliveryStatusTone", () => {
  it("maps every state colour design.md names", () => {
    expect(deliveryStatusTone("succeeded")).toEqual({ tone: "success", label: "Delivered" });
    expect(deliveryStatusTone("exhausted")).toEqual({ tone: "danger", label: "Failed" });
    expect(deliveryStatusTone("pending")).toEqual({ tone: "info", label: "Pending" });
  });
});

describe("endpointStatusTone", () => {
  it("treats disabled as neutral, not an error", () => {
    expect(endpointStatusTone("enabled").tone).toBe("success");
    expect(endpointStatusTone("disabled").tone).toBe("neutral");
  });
});

describe("breakerStateTone", () => {
  it("marks an open breaker the same danger tone as a failed delivery", () => {
    expect(breakerStateTone("open")).toEqual({ tone: "danger", label: "Open" });
    expect(breakerStateTone("closed").tone).toBe("success");
    expect(breakerStateTone("half_open").tone).toBe("warning");
  });
});
