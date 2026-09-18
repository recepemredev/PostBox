import { describe, expect, it } from "vitest";
import { apiErrorFrom } from "./errors";

describe("apiErrorFrom", () => {
  it("carries the message from a plain error body", () => {
    const error = apiErrorFrom(404, { message: "Not found" });

    expect(error.status).toBe(404);
    expect(error.message).toBe("Not found");
    expect(error.isNotFound).toBe(true);
  });

  it("only reads validation errors from a 422", () => {
    const error = apiErrorFrom(422, {
      message: "The given data was invalid.",
      errors: { name: ["The name field is required."] },
    });

    expect(error.isValidation).toBe(true);
    expect(error.fieldError("name")).toBe("The name field is required.");
    expect(error.fieldError("url")).toBeNull();
  });

  it("does not attach validation errors to a non-422 status even if the body has an errors key", () => {
    const error = apiErrorFrom(403, { message: "Forbidden", errors: { name: ["ignored"] } });

    expect(error.errors).toBeNull();
    expect(error.isForbidden).toBe(true);
  });

  it("falls back to a generic message when the body is not the expected shape", () => {
    const error = apiErrorFrom(500, null);

    expect(error.message).toBe("Request failed with status 500");
  });
});
