<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * HMAC-SHA256 over `{timestamp}.{payload}` — the whole specification is that
 * one sentence in CLAUDE.md, and this class is the executable form of it: the
 * conformance suite in Step 16 exists to prove the PHP and TypeScript SDKs
 * agree with what is written here, byte for byte, against the shared vectors
 * in `../contract/signature-vectors.json` (tests/Feature/Delivery/
 * SignatureConformanceTest.php runs them against this class; sdk/php and
 * sdk/ts run the same file against themselves).
 *
 * PostBox only ever signs; nothing in the delivery path calls verify(). It
 * exists beside sign() anyway, because a signature scheme demonstrated by a
 * signer nobody can check is not a rule this project can claim to hold to —
 * the tolerance boundary CLAUDE.md names is untestable without it, and a
 * consumer reading this file is reading the same check their own code has
 * to make.
 *
 * Both methods take plain secret strings rather than EndpointSecret models:
 * deciding which secrets are live is Expirable::current()'s job, done by the
 * caller, not this class's.
 */
final readonly class Signature
{
    private const string Version = 'v1';

    /**
     * The full PostBox-Signature header value: one `v1,<base64>` token per
     * secret, space-separated, in the order the secrets were given. During
     * rotation that is every active secret, so a consumer who has not yet
     * cut over to the new one still finds a token it trusts.
     *
     * @param  iterable<string>  $secrets
     */
    public function sign(string $payload, CarbonImmutable $timestamp, iterable $secrets): string
    {
        $signed = self::signedString($payload, $timestamp);

        $tokens = [];

        foreach ($secrets as $secret) {
            $tokens[] = self::Version.','.self::hmac($signed, $secret);
        }

        return implode(' ', $tokens);
    }

    /**
     * Whether the header carries a signature that at least one of the given
     * secrets would have produced, for a timestamp within tolerance of now.
     *
     * Every candidate is checked against every secret regardless of an
     * earlier pair already matching: stopping at the first match would leak,
     * through timing, which secret and which token verified.
     *
     * @param  iterable<string>  $secrets
     */
    public function verify(
        string $header,
        string $payload,
        CarbonImmutable $timestamp,
        CarbonImmutable $now,
        iterable $secrets,
    ): bool {
        $withinTolerance = abs($now->getTimestamp() - $timestamp->getTimestamp())
            <= Config::integer('postbox.signing.tolerance_seconds');

        $signed = self::signedString($payload, $timestamp);
        $candidates = self::decode($header);

        $verified = false;

        foreach ($secrets as $secret) {
            $expected = self::hmac($signed, $secret);

            foreach ($candidates as $candidate) {
                $verified = hash_equals($expected, $candidate) || $verified;
            }
        }

        return $withinTolerance && $verified;
    }

    /**
     * @return list<string>
     */
    private static function decode(string $header): array
    {
        $tokens = [];

        foreach (explode(' ', trim($header)) as $token) {
            [$version, $value] = array_pad(explode(',', $token, 2), 2, '');

            if ($version === self::Version && $value !== '') {
                $tokens[] = $value;
            }
        }

        return $tokens;
    }

    private static function hmac(string $signed, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $signed, $secret, binary: true));
    }

    private static function signedString(string $payload, CarbonImmutable $timestamp): string
    {
        return $timestamp->getTimestamp().'.'.$payload;
    }
}
