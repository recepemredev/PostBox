<?php

declare(strict_types=1);

use App\Support\Delivery\Signature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/*
 * Signature::sign()/verify() are the executable form of the specification (D53):
 * Step 16 holds sdk/php and sdk/ts to exactly what this class does, through one
 * vector file both SDKs also run — ../contract/signature-vectors.json. Every
 * expected value in it was computed independently with openssl, never with this
 * class, so this suite cannot become a mirror of its own implementation.
 *
 * This file replaces SignatureTest.php: every fixed-vector, rotation and
 * tolerance-boundary case it held now lives in the shared file instead, so the
 * two are never a second copy of the same cases (CLAUDE.md).
 */

/**
 * Read with a path relative to this file, not base_path(): Pest's own ->with()
 * dataset closures run during test collection, before the application is
 * booted, and base_path() needs app() to answer.
 *
 * @return array<string, mixed>
 */
function signatureVectors(): array
{
    return json_decode(
        file_get_contents(dirname(__DIR__, 4).'/contract/signature-vectors.json') ?: '{}',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * Each vector is a string-keyed array, and ->with() splats a case value that
 * is itself an array as named arguments rather than passing it through as
 * one value — so a bare vector row (keyed `name`, `payload`, …) is read as
 * "call the test with $name=…, $payload=…", which every test here has no
 * such parameters for. Wrapping each row in a one-element list keeps it a
 * single positional argument instead.
 *
 * @param  list<array<string, mixed>>  $vectors
 * @return array<string, array{0: array<string, mixed>}>
 */
function asDataset(array $vectors): array
{
    return collect($vectors)
        ->keyBy(fn (array $vector): string => (string) $vector['name'])
        ->map(fn (array $vector): array => [$vector])
        ->all();
}

beforeEach(function (): void {
    $this->signature = app(Signature::class);
    $this->vectors = signatureVectors();

    Config::set('postbox.signing.tolerance_seconds', $this->vectors['tolerance_seconds']);
});

it('signs exactly the vector predicts', function (array $vector): void {
    $header = $this->signature->sign(
        $vector['payload'],
        CarbonImmutable::createFromTimestamp($vector['timestamp'], 'UTC'),
        $vector['secrets'],
    );

    expect($header)->toBe($vector['header']);
})->with(fn (): array => asDataset(signatureVectors()['sign']));

it('verifies exactly as the vector predicts', function (array $vector): void {
    $result = $this->signature->verify(
        $vector['header'],
        $vector['payload'],
        CarbonImmutable::createFromTimestamp($vector['timestamp'], 'UTC'),
        CarbonImmutable::createFromTimestamp($vector['now'], 'UTC'),
        $vector['secrets'],
    );

    expect($result)->toBe($vector['valid']);
})->with(fn (): array => asDataset(signatureVectors()['verify']));

it('refuses every valid vector once one character of its signature is altered', function (array $vector): void {
    // Flips the first character of the token that actually verifies -- found by
    // its `v1,` prefix, wherever it sits among other tokens or trailing garbage
    // a vector may carry (an unknown-version token, a doubled space) -- rather
    // than the tail of the whole header string, which for those vectors would
    // land on something that was never part of the real signature at all.
    preg_match('/v1,([A-Za-z0-9+\/]+=*)/', (string) $vector['header'], $matches);
    $token = $matches[1];
    $flipped = ($token[0] === 'A' ? 'B' : 'A').substr($token, 1);
    $altered = preg_replace('/v1,'.preg_quote($token, '/').'/', "v1,{$flipped}", (string) $vector['header'], 1);

    $result = $this->signature->verify(
        $altered,
        $vector['payload'],
        CarbonImmutable::createFromTimestamp($vector['timestamp'], 'UTC'),
        CarbonImmutable::createFromTimestamp($vector['now'], 'UTC'),
        $vector['secrets'],
    );

    expect($result)->toBeFalse();
})->with(fn (): array => asDataset(collect(signatureVectors()['verify'])
    ->filter(fn (array $vector): bool => $vector['valid'])
    ->values()
    ->all()));

it('produces a different header once one byte of a signed payload is altered', function (array $vector): void {
    $tamperedPayload = $vector['payload'].'x';

    $header = $this->signature->sign(
        $tamperedPayload,
        CarbonImmutable::createFromTimestamp($vector['timestamp'], 'UTC'),
        $vector['secrets'],
    );

    expect($header)->not->toBe($vector['header']);
})->with(fn (): array => asDataset(signatureVectors()['sign']));
