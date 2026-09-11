<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one delivery stands: still trying, or done. This answers exactly that
 * question and nothing about why — the reason a pending delivery has not
 * succeeded yet (waiting on backoff, paused by an open breaker) lives in
 * attempt_count and next_attempt_at, and in the attempt records themselves, not
 * in a longer list of status values here.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';

    case Succeeded = 'succeeded';

    case Exhausted = 'exhausted';
}
