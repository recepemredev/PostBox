<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
 * The payload ceiling exists twice, and deliberately not as the same number.
 * Configuration holds the figure the ingest endpoint advertises and rejects on;
 * the CHECK constraint on messages.payload is the backstop every write to that
 * table passes through.
 *
 * Equality would be the wrong invariant, because the two do not measure the same
 * string. The constraint measures octet_length(payload::text), and PostgreSQL
 * renders jsonb with a space after every ':' and every ',' — so a payload sitting
 * exactly at the advertised ceiling on the wire is already over it once stored,
 * and the producer would get a 500 where a 422 was owed. The expansion is one
 * byte per structural separator and stays below 1.5x, so what is worth asserting
 * is that the endpoint always rejects first, with room left for the rendering.
 */

it('keeps the stored payload ceiling clear of the advertised one', function (): void {
    $constraint = DB::selectOne(
        'select pg_get_constraintdef(oid) as definition from pg_constraint '.
        "where conrelid = 'messages'::regclass and conname = ?",
        ['messages_payload_size_check'],
    );

    expect($constraint)->not->toBeNull();

    /** @var object{definition: string} $constraint */
    preg_match('/<=\s*(\d+)/', $constraint->definition, $matches);

    expect($matches)->toHaveKey(1)
        ->and((int) $matches[1])
        ->toBeGreaterThanOrEqual(Config::integer('postbox.ingest.max_payload_bytes') * 2);
});
