/**
 * The four error envelopes every backend response can carry
 * (`components["responses"]` in the generated contract): 401, 403, 404 and
 * 422. Mapped here once so a Server Action never re-parses a raw Response
 * body for itself.
 */

export type ValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: ValidationErrors | null = null,
  ) {
    super(message);
    this.name = "ApiError";
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isNotFound(): boolean {
    return this.status === 404;
  }

  /**
   * The first message for one field, for a form that shows one error per
   * input rather than a list.
   */
  fieldError(field: string): string | null {
    return this.errors?.[field]?.[0] ?? null;
  }
}

/**
 * Builds an ApiError from a response body already decoded as JSON. A pure
 * function: the caller reads the response, this turns the result into the
 * one shape the rest of the app handles.
 */
export function apiErrorFrom(status: number, body: unknown): ApiError {
  const record = typeof body === "object" && body !== null ? (body as Record<string, unknown>) : {};

  const message = typeof record.message === "string" ? record.message : `Request failed with status ${status}`;

  const errors =
    status === 422 && typeof record.errors === "object" && record.errors !== null
      ? (record.errors as ValidationErrors)
      : null;

  return new ApiError(message, status, errors);
}
