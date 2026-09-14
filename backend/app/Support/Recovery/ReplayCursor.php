<?php

declare(strict_types=1);

namespace App\Support\Recovery;

use App\Models\Delivery;
use Carbon\CarbonImmutable;

/**
 * A resume point for a bounded range replay: the last delivery the previous
 * page ended on, encoded opaquely so a caller carries it back and forth
 * without reading anything into it.
 *
 * Ordered by (exhausted_at, id) rather than exhausted_at alone — the column
 * is second-resolution, and a real outage can exhaust many deliveries in the
 * same second, so id is what breaks the tie without a page skipping or
 * repeating a row.
 */
final readonly class ReplayCursor
{
    private function __construct(public CarbonImmutable $exhaustedAt, public int $id) {}

    public static function after(Delivery $delivery): self
    {
        assert($delivery->exhausted_at !== null);

        return new self($delivery->exhausted_at, $delivery->id);
    }

    /**
     * Null for anything that is not exactly what encode() produces — a
     * cursor is opaque, and a caller that hand-edits one gets a validation
     * failure rather than this class guessing at what they meant.
     */
    public static function decode(string $token): ?self
    {
        $decoded = base64_decode($token, strict: true);

        if ($decoded === false || ! str_contains($decoded, '|')) {
            return null;
        }

        [$timestamp, $id] = explode('|', $decoded, 2);

        if ($timestamp === '' || ! ctype_digit($id)) {
            return null;
        }

        $exhaustedAt = CarbonImmutable::createFromFormat(CarbonImmutable::ATOM, $timestamp);

        return $exhaustedAt !== null ? new self($exhaustedAt, (int) $id) : null;
    }

    public function encode(): string
    {
        return base64_encode($this->exhaustedAt->toAtomString().'|'.$this->id);
    }
}
