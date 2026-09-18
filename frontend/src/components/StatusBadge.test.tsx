import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { StatusBadge } from "./StatusBadge";

describe("StatusBadge", () => {
  it("always renders the label as visible text, never colour alone", () => {
    render(<StatusBadge tone="danger" label="Failed" />);

    expect(screen.getByText("Failed")).toBeTruthy();
  });

  it.each(["success", "warning", "danger", "info", "neutral"] as const)(
    "renders the %s tone without throwing",
    (tone) => {
      render(<StatusBadge tone={tone} label={tone} />);

      expect(screen.getByText(tone)).toBeTruthy();
    },
  );
});
