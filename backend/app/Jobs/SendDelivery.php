<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;

/**
 * One delivery, handed to a worker.
 *
 * Queued retries are not this job's mechanism: a failed attempt leaves the
 * delivery pending and the dispatcher's own lease brings it back, so this
 * runs at most once per queue pop — hence tries=1 on the worker-deliveries
 * supervisor rather than a retry count here, which would be a second retry
 * schedule racing the outbox's.
 *
 * The two identifiers are the public ones rather than the row keys, because a
 * queue payload outlives the transaction that wrote it and a worker resolving
 * one has to establish the tenant before it can read anything at all.
 */
final class SendDelivery implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantId,
        public string $deliveryId,
    ) {
        $this->onQueue(Config::string('postbox.delivery.queue'));
    }

    public function handle(): void
    {
        // Step 6.
    }
}
