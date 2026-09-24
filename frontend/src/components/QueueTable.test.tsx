import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { QueueTable } from "./QueueTable";
import type { components } from "@/types/api";

type Queue = components["schemas"]["QueueWorkloadResource"];

function queue(overrides: Partial<Queue> = {}): Queue {
  return {
    name: "deliveries",
    length: 3,
    wait_ms: 2000,
    processes: 10,
    runtime_ms: 12,
    throughput: 42,
    ...overrides,
  };
}

describe("QueueTable", () => {
  it("renders one row per queue with every figure Horizon reports", () => {
    render(
      <QueueTable
        queues={[
          queue({ name: "deliveries" }),
          queue({ name: "retries", length: 0, wait_ms: 0, processes: 5, runtime_ms: 4, throughput: 0 }),
        ]}
      />,
    );

    expect(screen.getByText("deliveries")).toBeTruthy();
    expect(screen.getByText("retries")).toBeTruthy();
    expect(screen.getByText("2000ms")).toBeTruthy();
    expect(screen.getByText("42")).toBeTruthy();
  });

  it("labels the numbers as shared rather than this tenant's own", () => {
    render(<QueueTable queues={[queue()]} />);

    expect(screen.getByText("Shared across every tenant.")).toBeTruthy();
  });
});
