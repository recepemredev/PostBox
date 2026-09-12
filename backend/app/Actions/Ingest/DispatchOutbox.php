<?php

declare(strict_types=1);

namespace App\Actions\Ingest;

use App\Actions\Resilience\AdmitEndpoint;
use App\Enums\DeliveryStatus;
use App\Jobs\SendDelivery;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Support\Resilience\Admission;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The other half of the outbox: moving committed obligations onto the queue.
 *
 * Ingest writes a delivery and stops. This reads the ones that are due, claims
 * them, and hands each to a worker — which is what makes a crash between the
 * commit and the enqueue cost latency instead of a delivery. It runs for the
 * tenant that is current; walking the tenants is the caller's job, because
 * there is no such thing as a query across them here.
 *
 * Claiming is two things at once. `for update skip locked` means two dispatchers
 * running at the same time take disjoint sets rather than queueing behind each
 * other, and pushing next_attempt_at out by the lease means a delivery whose
 * worker never ran comes back on a later pass. Neither is a lock held for the
 * length of a send: the lease is the only thing that outlives this transaction.
 *
 * A claimed delivery still has to clear its endpoint's breaker before it
 * reaches a worker. That question is asked once per endpoint, not once per
 * delivery — everything claimed for the same endpoint gets the same answer,
 * because it is the endpoint that is open or closed, never one delivery on its
 * own. What the breaker refuses is deferred rather than queued: the lease this
 * claim already wrote is simply overwritten with whatever moment the breaker
 * says to try again, and nothing about the delivery otherwise changes — no
 * attempt is recorded, attempt_count does not move, and the row stays exactly
 * as pending as it was before this pass ever ran.
 */
final readonly class DispatchOutbox
{
    public function __construct(private TenantContext $context, private AdmitEndpoint $admit) {}

    /**
     * @return int how many deliveries were handed to the queue
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $claimed = $this->claim($now);

        if ($claimed->isEmpty()) {
            return 0;
        }

        $tenant = $this->context->currentOrFail()->public_id;
        $endpoints = $this->endpointsFor($claimed);

        $dispatched = 0;

        foreach ($claimed->groupBy('endpoint_id') as $endpointId => $deliveries) {
            $dispatched += $this->admitGroup($endpoints[$endpointId], $deliveries, $tenant, $now);
        }

        return $dispatched;
    }

    /**
     * @param  Collection<int, Delivery>  $deliveries  ordered oldest-due first, within this endpoint
     */
    private function admitGroup(Endpoint $endpoint, Collection $deliveries, string $tenant, CarbonImmutable $now): int
    {
        $admission = $this->admit->handle($endpoint, $now);

        if ($admission->allowsAll) {
            foreach ($deliveries as $delivery) {
                $this->send($tenant, $delivery);
            }

            return $deliveries->count();
        }

        if ($admission->allowsProbe) {
            /** @var Delivery $probe */
            $probe = $deliveries->shift();
            $this->send($tenant, $probe);

            $this->defer($deliveries, $admission, $now);

            return 1;
        }

        $this->defer($deliveries, $admission, $now);

        return 0;
    }

    private function send(string $tenant, Delivery $delivery): void
    {
        // A delivery with attempts behind it is a retry, and retries drain
        // on their own queue and their own workers (config/horizon.php).
        SendDelivery::dispatch($tenant, $delivery->public_id, $delivery->attempt_count > 0);
    }

    /**
     * @param  Collection<int, Delivery>  $deliveries
     */
    private function defer(Collection $deliveries, Admission $admission, CarbonImmutable $now): void
    {
        if ($deliveries->isEmpty()) {
            return;
        }

        // Only all() carries no `until`, and all() never reaches here — it
        // returns straight out of admitGroup() without deferring anything.
        assert($admission->until !== null);

        Delivery::query()
            ->whereIn('id', $deliveries->modelKeys())
            ->where('status', DeliveryStatus::Pending)
            ->update(['next_attempt_at' => max($admission->until, $now)]);
    }

    /**
     * @param  Collection<int, Delivery>  $claimed
     * @return array<array-key, Endpoint>
     */
    private function endpointsFor(Collection $claimed): array
    {
        return Endpoint::query()
            ->with('breaker')
            ->whereIn('id', $claimed->pluck('endpoint_id')->unique())
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @return Collection<int, Delivery>
     */
    private function claim(CarbonImmutable $now): Collection
    {
        $lease = $now->addSeconds(Config::integer('postbox.outbox.lease_seconds'));

        return DB::transaction(function () use ($now, $lease): Collection {
            /*
             * The partial index on (next_attempt_at) where status = 'pending' is
             * exactly this read. `lock()` takes the clause rather than the query
             * being written by hand: skip-locked has no builder method, and
             * without it a second dispatcher blocks on the first rather than
             * taking the rows it left.
             */
            $due = Delivery::query()
                ->where('status', DeliveryStatus::Pending)
                ->where('next_attempt_at', '<=', $now)
                ->orderBy('next_attempt_at')
                ->limit(Config::integer('postbox.outbox.batch_size'))
                ->lock('for update skip locked')
                ->get();

            if ($due->isNotEmpty()) {
                Delivery::query()
                    ->whereIn('id', $due->modelKeys())
                    ->update(['next_attempt_at' => $lease]);
            }

            return $due;
        });
    }
}
