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

];
