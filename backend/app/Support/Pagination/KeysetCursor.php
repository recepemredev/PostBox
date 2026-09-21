<?php

declare(strict_types=1);

namespace App\Support\Pagination;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * A resume point for a keyset-paginated list: the last row the previous page
 * ended on, encoded opaquely so a caller carries it back and forth without
 * reading anything into it.
 *
 * Ordered by (timestamp, tiebreak) rather than the timestamp alone — every
 * timestamp column this class pages against is second-resolution at best,
 * and two rows can share an instant, so the tiebreak is what makes a page
 * boundary land between rows rather than through a tied one. Generalized
 * from Step 9's own ReplayCursor (exhausted_at, id) at its second use: a
 * Ledger list pages the identical way, on (created_at, public_id).
 *
 * The tiebreak travels as a string rather than an int, because the two
 * callers need different columns behind it. Recovery's own range replay
 * carries an internal delivery id — never exposed, encoded into a value a
 * caller only ever echoes back in a POST body — while a Ledger list's cursor
 * travels in a URL query string and so carries a public_id instead;
 * "internal primary keys never appear in an API response or URL"
 * (architecture.md) rules the id form out there. PostgreSQL infers a bound
 * parameter's type from the column it is compared against, so the same
 * string-typed tiebreak still compares correctly against an integer id
 * column without an explicit cast.
 */
final readonly class KeysetCursor
{
    private function __construct(public CarbonImmutable $timestamp, public string $tiebreak) {}

    /**
     * Accepts either Carbon flavour: a model's own created_at is the
     * framework's default mutable Carbon unless a cast says otherwise
     * (Message and DeliveryAttempt cast nothing for it, matching every
     * timestamp FormatsTimestamps::utc() also accepts as CarbonInterface),
     * while Recovery's own exhausted_at is cast immutable. Converted once,
     * here, so this class's own internal representation — and every
     * comparison it builds — stays the immutable one regardless of which
     * flavour a caller handed it.
     */
    public static function after(CarbonInterface $timestamp, string $tiebreak): self
    {
        return new self(CarbonImmutable::instance($timestamp), $tiebreak);
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

        [$timestamp, $tiebreak] = explode('|', $decoded, 2);

        if ($timestamp === '' || $tiebreak === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat(CarbonImmutable::ATOM, $timestamp);

        return $parsed !== null ? new self($parsed, $tiebreak) : null;
    }

    public function encode(): string
    {
        return base64_encode($this->timestamp->toAtomString().'|'.$this->tiebreak);
    }

    /**
     * Strictly after this cursor's own row, in ($timestampColumn,
     * $tiebreakColumn) order. A plain "$timestampColumn > cursor" would skip
     * every row that shares its second; the tiebreak column is what a real
     * outage's same-second exhaustions need (D81), and what any other
     * second-resolution list needs for the same reason.
     *
     * @param  Builder<*>  $query
     */
    public function applyTo(Builder $query, string $timestampColumn = 'created_at', string $tiebreakColumn = 'public_id'): void
    {
        $timestamp = $this->timestamp;
        $tiebreak = $this->tiebreak;

        $query->where(function (Builder $outer) use ($timestampColumn, $tiebreakColumn, $timestamp, $tiebreak): void {
            $outer->where($timestampColumn, '>', $timestamp)
                ->orWhere(function (Builder $inner) use ($timestampColumn, $tiebreakColumn, $timestamp, $tiebreak): void {
                    $inner->where($timestampColumn, $timestamp)->where($tiebreakColumn, '>', $tiebreak);
                });
        });
    }
}
