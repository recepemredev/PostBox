<?php

declare(strict_types=1);

use PostBox\Webhook;
use Tests\Support\FixedClock;

/*
 * Step 16's shared vector file (../../contract/signature-vectors.json), run
 * against Webhook the same way App\Support\Delivery\Signature runs it on the
 * backend and sdk/ts runs it against itself — every expected value in it was
 * computed independently with openssl, never with this class.
 */

it('signs exactly the vector predicts', function (array $vector): void {
    $webhook = new Webhook;

    $header = $webhook->sign($vector['payload'], $vector['timestamp'], $vector['secrets']);

    expect($header)->toBe($vector['header']);
})->with(fn (): array => asDataset(signatureVectors()['sign']));

it('verifies exactly as the vector predicts', function (array $vector): void {
    $webhook = new Webhook(new FixedClock((new DateTimeImmutable)->setTimestamp($vector['now'])));

    $result = $webhook->verify(
        $vector['payload'],
        $vector['header'],
        (string) $vector['timestamp'],
        $vector['secrets'],
        signatureVectors()['tolerance_seconds'],
    );

    expect($result)->toBe($vector['valid']);
})->with(fn (): array => asDataset(signatureVectors()['verify']));

it('refuses every valid vector once one character of its signature is altered', function (array $vector): void {
    preg_match('/v1,([A-Za-z0-9+\/]+=*)/', (string) $vector['header'], $matches);
    $token = $matches[1];
    $flipped = ($token[0] === 'A' ? 'B' : 'A').substr($token, 1);
    $altered = preg_replace('/v1,'.preg_quote($token, '/').'/', "v1,{$flipped}", (string) $vector['header'], 1);

    $webhook = new Webhook(new FixedClock((new DateTimeImmutable)->setTimestamp($vector['now'])));

    $result = $webhook->verify(
        $vector['payload'],
        $altered,
        (string) $vector['timestamp'],
        $vector['secrets'],
        signatureVectors()['tolerance_seconds'],
    );

    expect($result)->toBeFalse();
})->with(fn (): array => asDataset(array_values(array_filter(
    signatureVectors()['verify'],
    fn (array $vector): bool => $vector['valid'],
))));

it('produces a different header once one byte of a signed payload is altered', function (array $vector): void {
    $webhook = new Webhook;

    $header = $webhook->sign($vector['payload'].'x', $vector['timestamp'], $vector['secrets']);

    expect($header)->not->toBe($vector['header']);
})->with(fn (): array => asDataset(signatureVectors()['sign']));

it('never verifies a non-numeric PostBox-Timestamp header', function (): void {
    $webhook = new Webhook;

    expect($webhook->verify('{}', 'v1,anything', 'not-a-number', ['whsec_test_secret']))->toBeFalse();
});

it('never verifies an empty PostBox-Timestamp header', function (): void {
    $webhook = new Webhook;

    expect($webhook->verify('{}', 'v1,anything', '', ['whsec_test_secret']))->toBeFalse();
});
