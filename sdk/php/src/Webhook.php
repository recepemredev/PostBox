<?php

declare(strict_types=1);

namespace PostBox;

use Psr\Clock\ClockInterface;

/**
 * The consumer side of PostBox's signature scheme: HMAC-SHA256 over
 * `{unix-seconds timestamp}.{raw body bytes}`, one `v1,<base64>` token per
 * active secret in the `PostBox-Signature` header, space-separated. Mirrors
 * App\Support\Delivery\Signature byte for byte — Step 16's conformance suite
 * (../../contract/signature-vectors.json) exists to prove it — with one
 * difference the backend has no code path for at all: verify() here reads
 * the raw `PostBox-Timestamp` header string, since that is what a receiving
 * webhook handler actually has, and returns false rather than throwing on a
 * timestamp that does not parse as an integer.
 */
final readonly class Webhook
{
    private const string VERSION = 'v1';

    public function __construct(
        private ?ClockInterface $clock = null,
    ) {}

    /**
     * @param  iterable<string>  $secrets
     */
    public function sign(string $payload, int $timestamp, iterable $secrets): string
    {
        $signed = self::signedString($payload, $timestamp);

        $tokens = [];

        foreach ($secrets as $secret) {
            $tokens[] = self::VERSION.','.self::hmac($signed, $secret);
        }

        return implode(' ', $tokens);
    }

    /**
     * Every candidate token is checked against every secret regardless of an
     * earlier pair already matching, for the same reason as the backend's
     * own verify(): stopping at the first match would leak, through timing,
     * which secret and which token verified.
     *
     * @param  iterable<string>  $secrets
     */
    public function verify(
        string $payload,
        string $signatureHeader,
        string $timestampHeader,
        iterable $secrets,
        int $toleranceSeconds = 300,
    ): bool {
        $timestamp = filter_var($timestampHeader, FILTER_VALIDATE_INT);

        if ($timestamp === false) {
            return false;
        }

        $now = $this->clock?->now()->getTimestamp() ?? time();
        $withinTolerance = abs($now - $timestamp) <= $toleranceSeconds;

        $signed = self::signedString($payload, $timestamp);
        $candidates = self::decode($signatureHeader);

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

            if ($version === self::VERSION && $value !== '') {
                $tokens[] = $value;
            }
        }

        return $tokens;
    }

    private static function hmac(string $signed, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $signed, $secret, binary: true));
    }

    private static function signedString(string $payload, int $timestamp): string
    {
        return $timestamp.'.'.$payload;
    }
}
