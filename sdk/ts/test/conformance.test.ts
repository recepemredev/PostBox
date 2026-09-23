import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { Webhook } from "../src/Webhook.js";

/*
 * Step 16's shared vector file (../../contract/signature-vectors.json), run
 * against Webhook the same way App\Support\Delivery\Signature runs it on
 * the backend and sdk/php runs it against itself — every expected value in
 * it was computed independently with openssl, never with this class.
 */

interface SignVector {
  name: string;
  payload: string;
  timestamp: number;
  secrets: string[];
  header: string;
}

interface VerifyVector extends SignVector {
  now: number;
  valid: boolean;
}

interface Vectors {
  tolerance_seconds: number;
  sign: SignVector[];
  verify: VerifyVector[];
}

function loadVectors(): Vectors {
  const path = fileURLToPath(new URL("../../../contract/signature-vectors.json", import.meta.url));

  return JSON.parse(readFileSync(path, "utf8")) as Vectors;
}

const vectors = loadVectors();

describe("sign", () => {
  it.each(vectors.sign.map((vector) => [vector.name, vector] as const))("%s", (_name, vector) => {
    const webhook = new Webhook();

    expect(webhook.sign(vector.payload, vector.timestamp, vector.secrets)).toBe(vector.header);
  });
});

describe("verify", () => {
  it.each(vectors.verify.map((vector) => [vector.name, vector] as const))("%s", (_name, vector) => {
    const webhook = new Webhook(() => new Date(vector.now * 1_000));

    const result = webhook.verify(
      vector.payload,
      vector.header,
      String(vector.timestamp),
      vector.secrets,
      vectors.tolerance_seconds,
    );

    expect(result).toBe(vector.valid);
  });
});

describe("altered vectors", () => {
  const validVectors = vectors.verify.filter((vector) => vector.valid);

  it.each(validVectors.map((vector) => [vector.name, vector] as const))(
    "refuses %s once one character of its signature is altered",
    (_name, vector) => {
      const match = /v1,([A-Za-z0-9+/]+=*)/.exec(vector.header);

      if (match === null) {
        throw new Error(`vector ${vector.name} has no v1 token`);
      }

      const token = match[1]!;
      const flipped = (token[0] === "A" ? "B" : "A") + token.slice(1);
      const altered = vector.header.replace(`v1,${token}`, `v1,${flipped}`);

      const webhook = new Webhook(() => new Date(vector.now * 1_000));

      const result = webhook.verify(
        vector.payload,
        altered,
        String(vector.timestamp),
        vector.secrets,
        vectors.tolerance_seconds,
      );

      expect(result).toBe(false);
    },
  );
});

describe("tampered payloads", () => {
  it.each(vectors.sign.map((vector) => [vector.name, vector] as const))(
    "produces a different header once one byte of %s is altered",
    (_name, vector) => {
      const webhook = new Webhook();

      const header = webhook.sign(`${vector.payload}x`, vector.timestamp, vector.secrets);

      expect(header).not.toBe(vector.header);
    },
  );
});

it("never verifies a non-numeric PostBox-Timestamp header", () => {
  const webhook = new Webhook();

  expect(webhook.verify("{}", "v1,anything", "not-a-number", ["whsec_test_secret"])).toBe(false);
});

it("never verifies an empty PostBox-Timestamp header", () => {
  const webhook = new Webhook();

  expect(webhook.verify("{}", "v1,anything", "", ["whsec_test_secret"])).toBe(false);
});
