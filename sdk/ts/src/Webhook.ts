import { createHmac, timingSafeEqual } from "node:crypto";

/**
 * The consumer side of PostBox's signature scheme: HMAC-SHA256 over
 * `{unix-seconds timestamp}.{raw body bytes}`, one `v1,<base64>` token per
 * active secret in the `PostBox-Signature` header, space-separated. Mirrors
 * App\Support\Delivery\Signature and sdk/php's Webhook byte for byte —
 * Step 16's conformance suite (../../contract/signature-vectors.json) exists
 * to prove it — with one difference the backend has no code path for at
 * all: verify() here reads the raw `PostBox-Timestamp` header string, since
 * that is what a receiving webhook handler actually has, and returns false
 * rather than throwing on a timestamp that does not parse as an integer.
 */
const VERSION = "v1";

export class Webhook {
  constructor(private readonly now: () => Date = () => new Date()) {}

  sign(payload: string, timestamp: number, secrets: readonly string[]): string {
    const signed = signedString(payload, timestamp);

    return secrets.map((secret) => `${VERSION},${hmac(signed, secret)}`).join(" ");
  }

  /**
   * Every candidate token is checked against every secret regardless of an
   * earlier pair already matching, for the same reason as the backend's own
   * verify(): stopping at the first match would leak, through timing, which
   * secret and which token verified.
   */
  verify(
    payload: string,
    signatureHeader: string,
    timestampHeader: string,
    secrets: readonly string[],
    toleranceSeconds = 300,
  ): boolean {
    const timestamp = parseTimestamp(timestampHeader);

    if (timestamp === null) {
      return false;
    }

    const nowSeconds = Math.floor(this.now().getTime() / 1_000);
    const withinTolerance = Math.abs(nowSeconds - timestamp) <= toleranceSeconds;

    const signed = signedString(payload, timestamp);
    const candidates = decode(signatureHeader);

    let verified = false;

    for (const secret of secrets) {
      const expected = hmac(signed, secret);

      for (const candidate of candidates) {
        verified = constantTimeEquals(expected, candidate) || verified;
      }
    }

    return withinTolerance && verified;
  }
}

function decode(header: string): string[] {
  const tokens: string[] = [];

  for (const token of header.trim().split(" ")) {
    const commaIndex = token.indexOf(",");
    const version = commaIndex === -1 ? token : token.slice(0, commaIndex);
    const value = commaIndex === -1 ? "" : token.slice(commaIndex + 1);

    if (version === VERSION && value !== "") {
      tokens.push(value);
    }
  }

  return tokens;
}

function hmac(signed: string, secret: string): string {
  return createHmac("sha256", secret).update(signed, "utf8").digest("base64");
}

function signedString(payload: string, timestamp: number): string {
  return `${timestamp}.${payload}`;
}

function parseTimestamp(header: string): number | null {
  return /^[+-]?\d+$/.test(header) ? Number.parseInt(header, 10) : null;
}

function constantTimeEquals(a: string, b: string): boolean {
  const bufferA = Buffer.from(a, "utf8");
  const bufferB = Buffer.from(b, "utf8");

  if (bufferA.length !== bufferB.length) {
    return false;
  }

  return timingSafeEqual(bufferA, bufferB);
}
