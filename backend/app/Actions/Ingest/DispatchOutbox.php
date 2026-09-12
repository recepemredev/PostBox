<?php

declare(strict_types=1);

namespace App\Actions\Ingest;

use App\Enums\DeliveryStatus;
use App\Jobs\SendDelivery;
use App\Models\Delivery;
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
 */
final readonly class DispatchOutbox
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return int how many deliveries were handed to the queue
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $claimed = $this->claim($now);

        $tenant = $this->context->currentOrFail()->public_id;

        foreach ($claimed as $delivery) {
            // A delivery with attempts behind it is a retry, and retries drain
            // on their own queue and their own workers (config/horizon.php).
            SendDelivery::dispatch($tenant, $delivery->public_id, $delivery->attempt_count > 0);
        }

        return $claimed->count();
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
