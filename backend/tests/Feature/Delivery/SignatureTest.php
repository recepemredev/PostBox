<?php

declare(strict_types=1);

use App\Support\Delivery\Signature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/*
 * The signature scheme is a specification, not an implementation detail
 * (Step 16 exists to prove exactly that), so the first test here is a fixed
 * vector computed independently with `openssl dgst -hmac`, not merely a
 * round trip through this class's own sign(). Everything after it exercises
 * the properties CLAUDE.md names: rotation, and a fixed timestamp tolerance.
 */

beforeEach(function (): void {
    $this->signature = app(Signature::class);

    $this->secret = 'whsec_test_secret';
    $this->previousSecret = 'whsec_previous_secret';
    $this->payload = '{"event_type":"invoice.paid","total":4200}';
    $this->now = CarbonImmutable::createFromTimestamp(1_700_000_000, 'UTC');

    // Both computed independently of this class, over `{timestamp}.{payload}`
    // with the secret named: `printf '%s' '1700000000.{payload}' |
    // openssl dgst -sha256 -hmac '<secret>' -binary | openssl base64`.
    $this->expectedSignature = 'a9AAHA7JMeG0INHNN14js11yp3i9Z9UrkM2YkkuySLo=';
    $this->expectedPreviousSignature = 'TlTFwOhFgHQRB2L54lIdnyOUTeUBcSiyCBRWBVhRBsw=';
});

it('matches a signature computed independently with openssl', function (): void {
    $header = $this->signature->sign($this->payload, $this->now, [$this->secret]);

    expect($header)->toBe("v1,{$this->expectedSignature}");
});

it('verifies the same vector back', function (): void {
    $header = "v1,{$this->expectedSignature}";

    expect($this->signature->verify($header, $this->payload, $this->now, $this->now, [$this->secret]))
        ->toBeTrue();
});

it('carries one token per active secret during rotation', function (): void {
    $header = $this->signature->sign($this->payload, $this->now, [$this->secret, $this->previousSecret]);

    expect($header)->toBe(
        "v1,{$this->expectedSignature} v1,{$this->expectedPreviousSignature}"
    );
});

it('verifies against whichever active secret produced the header, not only the first', function (): void {
    // A consumer signed with the old secret while both were active; PostBox
    // now signs with the new one only. The old header must still verify
    // against the secret the endpoint has not rotated away from yet.
    $header = $this->signature->sign($this->payload, $this->now, [$this->previousSecret]);

    expect($this->signature->verify($header, $this->payload, $this->now, $this->now, [
        $this->secret,
        $this->previousSecret,
    ]))->toBeTrue();
});

it('refuses a signature from a secret that was never active', function (): void {
    $header = $this->signature->sign($this->payload, $this->now, ['whsec_wrong_secret']);

    expect($this->signature->verify($header, $this->payload, $this->now, $this->now, [$this->secret]))
        ->toBeFalse();
});

it('refuses a signature computed over a different payload', function (): void {
    $header = $this->signature->sign($this->payload, $this->now, [$this->secret]);

    expect($this->signature->verify($header, '{"tampered":true}', $this->now, $this->now, [$this->secret]))
        ->toBeFalse();
});

it('accepts a timestamp exactly at the tolerance boundary', function (): void {
    $tolerance = Config::integer('postbox.signing.tolerance_seconds');
    $header = $this->signature->sign($this->payload, $this->now, [$this->secret]);
    $verifiedAt = $this->now->addSeconds($tolerance);

    expect($this->signature->verify($header, $this->payload, $this->now, $verifiedAt, [$this->secret]))
        ->toBeTrue();
});

it('refuses a timestamp one second past the tolerance boundary', function (): void {
    $tolerance = Config::integer('postbox.signing.tolerance_seconds');
    $header = $this->signature->sign($this->payload, $this->now, [$this->secret]);
    $verifiedAt = $this->now->addSeconds($tolerance + 1);

    expect($this->signature->verify($header, $this->payload, $this->now, $verifiedAt, [$this->secret]))
        ->toBeFalse();
});

it('refuses a timestamp as far in the past as the tolerance allows in the future', function (): void {
    $tolerance = Config::integer('postbox.signing.tolerance_seconds');
    $header = $this->signature->sign($this->payload, $this->now, [$this->secret]);
    $verifiedAt = $this->now->subSeconds($tolerance + 1);

    expect($this->signature->verify($header, $this->payload, $this->now, $verifiedAt, [$this->secret]))
        ->toBeFalse();
});

it('refuses a header carrying no recognizable token', function (): void {
    expect($this->signature->verify('garbage', $this->payload, $this->now, $this->now, [$this->secret]))
        ->toBeFalse();
});
