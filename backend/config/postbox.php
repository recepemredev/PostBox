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

    /*
    |--------------------------------------------------------------------------
    | Governor
    |--------------------------------------------------------------------------
    |
    | Two limits, kept genuinely separate. rate_limit is a token bucket:
    | capacity is the burst a tenant can spend at once and refill_per_second is
    | how fast it comes back. quota is cumulative and resets on the calendar
    | month; messages_per_period is the ceiling for that period.
    |
    | These are env values, unlike max_payload_bytes: a plan's numbers are
    | still a product decision, not a deployment knob, but Step 10 has to
    | drive the ingest path at the rates benchmarking.md's Phases 2-5 ask for,
    | and the pro plan's own 100 rps refill is below what a scaling curve
    | needs to measure the delivery path rather than the limiter. Every
    | default below reproduces the product numbers exactly, so an operator who
    | never sets these gets precisely what shipped before this existed —
    | compose.bench.yaml is the only place any of them are actually
    | overridden, and only for the pro plan the benchmark tenant is seeded on.
    |
    */
    'governor' => [
        'rate_limit' => [
            'free' => [
                'capacity' => (int) env('POSTBOX_RATE_LIMIT_FREE_CAPACITY', 20),
                'refill_per_second' => (int) env('POSTBOX_RATE_LIMIT_FREE_REFILL_PER_SECOND', 10),
            ],
            'pro' => [
                'capacity' => (int) env('POSTBOX_RATE_LIMIT_PRO_CAPACITY', 200),
                'refill_per_second' => (int) env('POSTBOX_RATE_LIMIT_PRO_REFILL_PER_SECOND', 100),
            ],
        ],

        'quota' => [
            'free' => ['messages_per_period' => (int) env('POSTBOX_QUOTA_FREE_MESSAGES_PER_PERIOD', 10_000)],
            'pro' => ['messages_per_period' => (int) env('POSTBOX_QUOTA_PRO_MESSAGES_PER_PERIOD', 1_000_000)],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | queue carries a delivery's first hand-off and retry_queue every one after
    | it, drained by two worker services that scale independently
    | (config/horizon.php, compose.yaml). The split is what stops a backlog of
    | retries from starving events that have not been tried even once.
    |
    | connect_timeout_ms and timeout_ms are env values, not literals: unlike a
    | plan's numbers, how long this deployment is willing to wait on a
    | customer's server is an operational knob, not a product decision.
    | config/horizon.php reads the same POSTBOX_DELIVERY_TIMEOUT_MS to size a
    | worker's own timeout past it, so the two cannot drift by editing one.
    |
    | max_recorded_body_bytes is the same 256 KiB ceiling
    | delivery_attempts.request_body and .response_body hold themselves to at
    | the database (Step 3) — recording more than the row will accept would be
    | truncated a second time, silently, by the CHECK constraint instead of
    | deliberately, by this cap.
    |
    | scrubbed_headers never reach a stored attempt. Comparison is
    | case-insensitive; PostBox-Signature is not in this list; a signature is
    | derived, not secret, and Step 14's inspector needs it to explain a
    | consumer's "the signature didn't verify" report.
    |
    */
    'delivery' => [
        'queue' => env('POSTBOX_DELIVERY_QUEUE', 'deliveries'),
        'retry_queue' => env('POSTBOX_DELIVERY_RETRY_QUEUE', 'retries'),

        'connect_timeout_ms' => (int) env('POSTBOX_DELIVERY_CONNECT_TIMEOUT_MS', 5000),
        'timeout_ms' => (int) env('POSTBOX_DELIVERY_TIMEOUT_MS', 15000),

        'max_recorded_body_bytes' => 262144,

        'scrubbed_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
            'proxy-authorization',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | The whole retry schedule, in one place, so it can be read off on paper and
    | pinned in a test. max_attempts counts the first attempt, so 8 means one
    | send and seven retries. The delay for attempt n is equal jitter over an
    | exponential base:
    |
    |     temp  = min(ceiling_ms, base_delay_ms * growth_factor ** (n - 1))
    |     delay = temp / 2 + jitter() * temp / 2
    |
    | Half the delay is therefore fixed and half is spread, which keeps the
    | sequence recognisably exponential while still pulling a thundering herd
    | apart — an endpoint that drops a thousand deliveries at once does not get
    | all thousand back in the same second.
    |
    | With jitter pinned at 0.5 the sequence is 3.75s, 11.25s, 33.75s, 101.25s,
    | 303.75s, 911.25s, 2733.75s, then the ceiling's 2700s: about an hour and
    | twenty minutes of trying before a delivery is dead-lettered.
    |
    | One floor is not in this arithmetic: the dispatcher claims on a one-minute
    | schedule, so a delay shorter than a minute is rounded up to its next pass.
    | base_delay_ms is a lower bound on the wait, never the wait itself.
    |
    | These are env values, for the same reason the delivery timeouts are: how
    | long this deployment is willing to keep trying a customer's server is an
    | operational decision, not a product one.
    |
    */
    'retry' => [
        'max_attempts' => (int) env('POSTBOX_RETRY_MAX_ATTEMPTS', 8),
        'base_delay_ms' => (int) env('POSTBOX_RETRY_BASE_DELAY_MS', 5_000),
        'growth_factor' => (int) env('POSTBOX_RETRY_GROWTH_FACTOR', 3),
        'ceiling_ms' => (int) env('POSTBOX_RETRY_CEILING_MS', 3_600_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signing
    |--------------------------------------------------------------------------
    |
    | tolerance_seconds bounds how far a signed timestamp may drift from the
    | verifying clock, in either direction, before the signature is refused —
    | wide enough to absorb ordinary clock skew, narrow enough that a captured
    | request cannot be replayed long after the fact.
    |
    */
    'signing' => [
        'tolerance_seconds' => (int) env('POSTBOX_SIGNATURE_TOLERANCE_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | One endpoint that keeps failing should not go on consuming worker
    | capacity a request at a time. failure_threshold is how many non-success
    | attempts inside window_seconds trip the breaker; open_seconds is how long
    | it then refuses every delivery outright. probe_timeout_seconds is how
    | long a single admitted probe is trusted to still be in flight before a
    | later dispatcher pass treats it as abandoned and admits a fresh one — the
    | same "the worker that claimed this never ran" reasoning the outbox lease
    | already makes, so it matches that lease by default.
    |
    | These are env values, for the same reason the retry schedule's are: how
    | long this deployment is willing to hold a customer's endpoint off is an
    | operational decision, not a product one.
    |
    */
    'breaker' => [
        'failure_threshold' => (int) env('POSTBOX_BREAKER_FAILURE_THRESHOLD', 5),
        'window_seconds' => (int) env('POSTBOX_BREAKER_WINDOW_SECONDS', 60),
        'open_seconds' => (int) env('POSTBOX_BREAKER_OPEN_SECONDS', 60),
        'probe_timeout_seconds' => (int) env('POSTBOX_BREAKER_PROBE_TIMEOUT_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay
    |--------------------------------------------------------------------------
    |
    | max_deliveries_per_request bounds one range replay the same way
    | outbox.batch_size bounds one dispatcher pass: an operator recovering a
    | large outage cannot hold a transaction open for the whole window, so a
    | call takes at most this many exhausted deliveries and hands back a
    | cursor for the rest. An env value, not a literal — how large a single
    | recovery call is allowed to be is an operational decision, not a
    | product one, the same reasoning the outbox lease and the retry schedule
    | already carry.
    |
    */
    'replay' => [
        'max_deliveries_per_request' => (int) env('POSTBOX_REPLAY_BATCH_SIZE', 200),
    ],

];
