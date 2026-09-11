<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Monthly partitions
    |--------------------------------------------------------------------------
    |
    | months_ahead is how far past the current month the scheduled command keeps
    | a partition open, so a clock skew or a missed run never leaves the very
    | next month without one. retention_months is how long a table keeps a
    | partition after its month has passed. Attempts are retained for a shorter
    | window than messages: they are the higher-volume, more diagnostic of the
    | two — a tenant is far more likely to want their own event history back
    | than the raw HTTP traces behind a delivery from a year ago.
    |
    */
    'partitions' => [
        'months_ahead' => (int) env('POSTBOX_PARTITION_MONTHS_AHEAD', 3),

        'retention_months' => [
            'messages' => (int) env('POSTBOX_MESSAGE_RETENTION_MONTHS', 6),
            'delivery_attempts' => (int) env('POSTBOX_ATTEMPT_RETENTION_MONTHS', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    |
    | max_payload_bytes is 256 KiB, and it is a literal rather than an env
    | value on purpose: the same ceiling is a CHECK constraint on
    | messages.payload, and a figure the environment could move would drift away
    | from a figure the schema cannot. A test asserts the two are equal, which
    | is only worth asserting while both are fixed.
    |
    | idempotency.ttl_hours is how long a spent key stays reserved. Past it the
    | key is prunable and a producer replaying it gets a new message rather than
    | the original — long enough to cover any retry a client is still making,
    | short enough that the table does not grow with the message log.
    |
    */
    'ingest' => [
        'max_payload_bytes' => 262144,

        'idempotency' => [
            'ttl_hours' => (int) env('POSTBOX_IDEMPOTENCY_TTL_HOURS', 24),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox
    |--------------------------------------------------------------------------
    |
    | lease_seconds is how long a claimed delivery is held off the dispatcher's
    | next pass. It is not a guess at how long a send takes — it is how long the
    | system is willing to wait before assuming the worker that claimed it never
    | ran, which is what turns "at-least-once" into something a crash cannot
    | break. Too short and a slow delivery is sent twice; too long and a lost one
    | waits. Five minutes is comfortably past any request timeout a delivery can
    | have.
    |
    | batch_size bounds one pass, so a tenant with a large backlog cannot hold
    | the dispatcher inside a single transaction while every other tenant waits.
    |
    */
    'outbox' => [
        'lease_seconds' => (int) env('POSTBOX_OUTBOX_LEASE_SECONDS', 300),
        'batch_size' => (int) env('POSTBOX_OUTBOX_BATCH_SIZE', 500),
    ],

];
