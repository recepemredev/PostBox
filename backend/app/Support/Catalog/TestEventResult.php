<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\Delivery;
use App\Models\Message;

/**
 * What sending a test event produced: the message it wrote (marked
 * MessageSource::DashboardTest) and the one delivery it opened, so the
 * operator screen that triggered it can land on that delivery rather than
 * the message list.
 */
final readonly class TestEventResult
{
    public function __construct(public Message $message, public Delivery $delivery) {}
}
