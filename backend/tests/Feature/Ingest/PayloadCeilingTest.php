<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
 * The payload ceiling exists twice: once in configuration, where the ingest
 * endpoint reads it to reject an oversized body with a 422 instead of a database
 * error, and once as a CHECK constraint on messages.payload, which is the
 * backstop every write to that table passes through. Two copies of a number are
 * a drift waiting to happen, so the only thing that makes the arrangement
 * defensible is this test.
 */

it('holds the configured payload ceiling equal to the database constraint', function (): void {
    $definition = DB::selectOne(
        'select pg_get_constraintdef(oid) as definition from pg_constraint where conname = ?',
        ['messages_payload_size_check'],
    );

    expect($definition)->not->toBeNull();

    /** @var object{definition: string} $definition */
    preg_match('/<=\s*(\d+)/', $definition->definition, $matches);

    expect($matches)->toHaveKey(1)
        ->and((int) $matches[1])->toBe(Config::integer('postbox.ingest.max_payload_bytes'));
});
