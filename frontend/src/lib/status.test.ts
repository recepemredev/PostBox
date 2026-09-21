import { describe, expect, it } from "vitest";
import {
  attemptOutcomeTone,
  breakerStateTone,
  deliveryStatusTone,
  endpointStatusTone,
  messageDeliveryTone,
} from "./status";

describe("deliveryStatusTone", () => {
  it("maps every state colour design.md names", () => {
    expect(deliveryStatusTone("succeeded")).toEqual({ tone: "success", label: "Delivered" });
    expect(deliveryStatusTone("exhausted")).toEqual({ tone: "danger", label: "Failed" });
    expect(deliveryStatusTone("pending")).toEqual({ tone: "info", label: "Pending" });
  });

  it("tells a fresh pending delivery apart from one already retrying", () => {
    expect(deliveryStatusTone("pending", 0)).toEqual({ tone: "info", label: "Pending" });
    expect(deliveryStatusTone("pending", 2)).toEqual({ tone: "warning", label: "Retrying" });
  });
});

describe("messageDeliveryTone", () => {
  it("is Failed the moment any delivery is exhausted, even with others succeeded", () => {
    expect(messageDeliveryTone({ total: 3, succeeded: 2, pending: 0, exhausted: 1 })).toEqual({
      tone: "danger",
      label: "Failed",
    });
  });

  it("is Pending when nothing has failed but something is still in flight", () => {
    expect(messageDeliveryTone({ total: 2, succeeded: 1, pending: 1, exhausted: 0 })).toEqual({
      tone: "info",
      label: "Pending",
    });
  });

  it("is Delivered only once every delivery succeeded", () => {
    expect(messageDeliveryTone({ total: 2, succeeded: 2, pending: 0, exhausted: 0 })).toEqual({
      tone: "success",
      label: "Delivered",
    });
  });

  it("is neutral for a message with no subscribers", () => {
    expect(messageDeliveryTone({ total: 0, succeeded: 0, pending: 0, exhausted: 0 }).tone).toBe("neutral");
  });
});

describe("attemptOutcomeTone", () => {
  it("marks a blocked attempt danger, the same as a failed one — PostBox refusing to send is not a neutral outcome", () => {
    expect(attemptOutcomeTone("blocked").tone).toBe("danger");
    expect(attemptOutcomeTone("failed").tone).toBe("danger");
  });

  it("marks a succeeded attempt success", () => {
    expect(attemptOutcomeTone("succeeded")).toEqual({ tone: "success", label: "Succeeded" });
  });

  it.each(["timeout", "dns_error", "tls_error", "connection_error"] as const)(
    "marks %s danger",
    (outcome) => {
      expect(attemptOutcomeTone(outcome).tone).toBe("danger");
    },
  );
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
