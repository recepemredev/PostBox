<?php

declare(strict_types=1);

namespace App\Support\Resilience;

use Carbon\CarbonImmutable;

/**
 * What AdmitEndpoint decided about the deliveries claimed for one endpoint: let
 * every one of them go, let exactly one through as a probe, or hold all of
 * them back until a given moment. The caller writes the state; it never works
 * out any of this for itself.
 *
 * `until` is absent only on `all()`, where nothing is left behind to defer.
 * A probe still carries one: it is not for the probe itself, which is sent
 * immediately, but for whatever else was claimed alongside it — the rest of
 * the batch waits for the probe to have had its chance to conclude, which is
 * the probe's own timeout, not a fresh reading of the breaker.
 */
final readonly class Admission
{
    private function __construct(
        public bool $allowsAll,
        public bool $allowsProbe,
        public ?CarbonImmutable $until = null,
    ) {}

    public static function all(): self
    {
        return new self(allowsAll: true, allowsProbe: false);
    }

    public static function probe(CarbonImmutable $othersDeferUntil): self
    {
        return new self(allowsAll: false, allowsProbe: true, until: $othersDeferUntil);
    }

    public static function deferUntil(CarbonImmutable $until): self
    {
        return new self(allowsAll: false, allowsProbe: false, until: $until);
    }
}
