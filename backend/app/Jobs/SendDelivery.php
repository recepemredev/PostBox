<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Delivery\AttemptDelivery;
use App\Models\Delivery;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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
 * Which queue it lands on is the dispatcher's call, not this job's: only the
 * dispatcher knows whether it is handing over a delivery that has never been
 * tried or one that is coming back. The two drain on separate worker services,
 * so a backlog of retries against a broken endpoint cannot starve events that
 * have not had their first attempt yet.
 *
 * The two identifiers are the public ones rather than the row keys, because a
 * queue payload outlives the transaction that wrote it and a worker resolving
 * one has to establish the tenant before it can read anything at all. Both
 * are resolved here, under the tenant the job establishes for itself, rather
 * than in AttemptDelivery — that action takes an already-resolved Delivery
 * because every other Action in this codebase takes its models resolved,
 * not looked up by identifier.
 */
final class SendDelivery implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantId,
        public string $deliveryId,
        public bool $isRetry = false,
    ) {
        $this->onQueue(Config::string($isRetry ? 'postbox.delivery.retry_queue' : 'postbox.delivery.queue'));
    }

    public function handle(TenantContext $context, AttemptDelivery $attempt): void
    {
        $tenant = Tenant::query()->where('public_id', $this->tenantId)->firstOrFail();

        $context->runFor($tenant, function () use ($attempt): void {
            $delivery = Delivery::query()
                ->with('endpoint')
                ->where('public_id', $this->deliveryId)
                ->firstOrFail();

            $attempt->handle($delivery, CarbonImmutable::now());
        });
    }
}
