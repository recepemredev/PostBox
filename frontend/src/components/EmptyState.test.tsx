import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { EmptyState } from "./EmptyState";

describe("EmptyState", () => {
  it("explains what to do next rather than only saying there is no data", () => {
    render(<EmptyState title="No applications yet" hint="Create one to get started." />);

    expect(screen.getByText("No applications yet")).toBeTruthy();
    expect(screen.getByText("Create one to get started.")).toBeTruthy();
  });

  it("renders the action when one is given, and omits it otherwise", () => {
    const { rerender } = render(<EmptyState title="Empty" hint="Hint" action={<button>Do it</button>} />);

    expect(screen.getByText("Do it")).toBeTruthy();

    rerender(<EmptyState title="Empty" hint="Hint" />);

    expect(screen.queryByText("Do it")).toBeNull();
  });
});
