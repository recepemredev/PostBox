<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The SSRF guard (Step 6) runs ahead of every send and can refuse a target
 * before a single byte leaves the process — no attempt, no response, nothing
 * DNS or TLS could have said either way. That refusal still has to leave a
 * row: an inspector reading "why didn't this endpoint receive anything" needs
 * a different answer from "it timed out" or "the connection failed", which
 * are network facts this one is not.
 *
 * Both CHECK constraints from the delivery_attempts migration are widened for
 * the new value, in place. delivery_attempts is partitioned, and a CHECK
 * constraint declared on the parent — as both of these are — is inherited by
 * every partition automatically, so altering the parent alone is enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE delivery_attempts DROP CONSTRAINT delivery_attempts_outcome_check');
        DB::statement(
            'ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_outcome_check '.
            "CHECK (outcome IN ('succeeded', 'failed', 'timeout', 'dns_error', 'tls_error', 'connection_error', 'blocked'))"
        );

        DB::statement('ALTER TABLE delivery_attempts DROP CONSTRAINT delivery_attempts_response_status_shape_check');
        DB::statement(
            'ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_response_status_shape_check CHECK ('.
                "(outcome = 'succeeded' AND response_status BETWEEN 200 AND 299) OR ".
                '(outcome = \'failed\' AND response_status BETWEEN 100 AND 599 '.
                'AND response_status NOT BETWEEN 200 AND 299) OR '.
                "(outcome IN ('timeout', 'dns_error', 'tls_error', 'connection_error', 'blocked') ".
                'AND response_status IS NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE delivery_attempts DROP CONSTRAINT delivery_attempts_response_status_shape_check');
        DB::statement(
            'ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_response_status_shape_check CHECK ('.
                "(outcome = 'succeeded' AND response_status BETWEEN 200 AND 299) OR ".
                '(outcome = \'failed\' AND response_status BETWEEN 100 AND 599 '.
                'AND response_status NOT BETWEEN 200 AND 299) OR '.
                "(outcome IN ('timeout', 'dns_error', 'tls_error', 'connection_error') ".
                'AND response_status IS NULL))'
        );

        DB::statement('ALTER TABLE delivery_attempts DROP CONSTRAINT delivery_attempts_outcome_check');
        DB::statement(
            'ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_outcome_check '.
            "CHECK (outcome IN ('succeeded', 'failed', 'timeout', 'dns_error', 'tls_error', 'connection_error'))"
        );
    }
};
