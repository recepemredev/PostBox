<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One delivery, handed to a worker.
 *
 * The body of this job is Step 6's: signing, the outbound transport, the SSRF
 * guard and the attempt record do not exist yet, and writing any of them here
 * would be building the delivery engine a step early. What does exist now is
 * everything around it — the dispatcher that claims a delivery from the outbox,
 * the lease that brings it back if this job never runs, and the guarantee that
 * the request path enqueued nothing itself. Those need something real to be
 * handed to, and this is it.
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
    ) {}

    public function handle(): void
    {
        // Step 6.
    }
}
