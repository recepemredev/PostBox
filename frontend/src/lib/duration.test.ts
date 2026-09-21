import { describe, expect, it } from "vitest";
import { formatDuration } from "./duration";

describe("formatDuration", () => {
  it("renders a sub-second span in milliseconds", () => {
    expect(formatDuration(500)).toBe("500ms");
  });

  it("renders whole seconds", () => {
    expect(formatDuration(5000)).toBe("5s");
  });

  it("renders minutes and seconds", () => {
    expect(formatDuration(65_000)).toBe("1m 5s");
  });

  it("drops a zero seconds remainder", () => {
    expect(formatDuration(120_000)).toBe("2m");
  });

  it("renders hours and minutes", () => {
    expect(formatDuration(3_725_000)).toBe("1h 2m");
  });

  it("drops a zero minutes remainder", () => {
    expect(formatDuration(7_200_000)).toBe("2h");
  });
});
