import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { BreakerList } from "./BreakerList";
import type { components } from "@/types/api";

type Breakers = components["schemas"]["OperationsResource"]["breakers"];

function breakers(overrides: Partial<Breakers> = {}): Breakers {
  return {
    closed: 3,
    open: 0,
    half_open: 0,
    tripped: [],
    ...overrides,
  };
}

describe("BreakerList", () => {
  it("shows an empty state, not a bare zero, when nothing is tripped", () => {
    render(<BreakerList breakers={breakers()} />);

    expect(screen.getByText("All circuits closed")).toBeTruthy();
    expect(screen.getByText("3 closed")).toBeTruthy();
  });

  it("lists a tripped breaker with its own state and endpoint name, in place of the empty state", () => {
    render(
      <BreakerList
        breakers={breakers({
          closed: 2,
          open: 1,
          tripped: [
            {
              endpoint_id: "ep_1",
              endpoint_name: "Billing webhook",
              state: "open",
              state_changed_at: "2026-09-24T00:00:00Z",
            },
          ],
        })}
      />,
    );

    expect(screen.queryByText("All circuits closed")).toBeNull();
    expect(screen.getByText("Billing webhook")).toBeTruthy();
    expect(screen.getByText("Open")).toBeTruthy();
    expect(screen.getByText("1 open")).toBeTruthy();
  });
});
