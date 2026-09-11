<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The stored ceiling is raised to twice the ceiling the ingest endpoint
 * advertises, so that the endpoint is always what rejects an oversized payload
 * and this constraint is never what turns one into a 500.
 *
 * The two cannot be the same number, because they do not measure the same
 * string. This constraint measures octet_length(payload::text), and PostgreSQL
 * renders jsonb with a space after every ':' and every ',': {"a":1,"b":2} is
 * thirteen bytes on the wire and sixteen in that expression. The expansion is
 * one byte per structural separator, so the worst case is an array of
 * single-digit numbers — 3n bytes rendered against 2n+1 sent, which approaches
 * 1.5x without reaching it. Escaping only ever shrinks the rendering, since
 * jsonb unescapes \u00e9 to the two bytes of é.
 *
 * 2x is that bound with room to spare, and it leaves the advertised figure a
 * round 256 KiB. config/postbox.php holds the advertised one; a test asserts
 * this one stays at least twice it.
 */
return new class extends Migration
{
    private const ADVERTISED = 262144;

    private const STORED = self::ADVERTISED * 2;

    public function up(): void
    {
        $this->ceiling(self::STORED);
    }

    public function down(): void
    {
        $this->ceiling(self::ADVERTISED);
    }

    /**
     * Dropping and re-adding is the only way to change a CHECK. On a partitioned
     * table both statements cascade to every partition, so the constraint stays
     * one fact rather than one per month.
     */
    private function ceiling(int $bytes): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_payload_size_check');

        DB::statement(
            'ALTER TABLE messages ADD CONSTRAINT messages_payload_size_check '.
            "CHECK (octet_length(payload::text) <= {$bytes})"
        );
    }
};
