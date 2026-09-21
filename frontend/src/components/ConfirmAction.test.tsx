import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { ConfirmAction } from "./ConfirmAction";

describe("ConfirmAction", () => {
  it("shows only the label until it is armed, then shows the description and the confirm step", () => {
    render(
      <ConfirmAction label="Replay to this endpoint" description="This opens a new delivery." confirm={<button>Confirm replay</button>} />,
    );

    expect(screen.queryByText("This opens a new delivery.")).toBeNull();
    expect(screen.queryByText("Confirm replay")).toBeNull();

    fireEvent.click(screen.getByText("Replay to this endpoint"));

    expect(screen.getByText("This opens a new delivery.")).toBeTruthy();
    expect(screen.getByText("Confirm replay")).toBeTruthy();
  });

  it("disarms on cancel, back to only the label", () => {
    render(<ConfirmAction label="Replay" description="Description" confirm={<button>Confirm</button>} />);

    fireEvent.click(screen.getByText("Replay"));
    fireEvent.click(screen.getByText("Cancel"));

    expect(screen.queryByText("Description")).toBeNull();
  });

  it("renders disabled with its reason, and never arms, when disabled", () => {
    render(
      <ConfirmAction
        label="Replay this range"
        description="Description"
        confirm={<button>Confirm</button>}
        disabled
        disabledReason="Select one endpoint and a time range first."
      />,
    );

    expect(screen.getByText("Select one endpoint and a time range first.")).toBeTruthy();

    fireEvent.click(screen.getByText("Replay this range"));

    expect(screen.queryByText("Description")).toBeNull();
  });
});
