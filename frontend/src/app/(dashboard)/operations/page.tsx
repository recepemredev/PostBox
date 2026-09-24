import { BreakerList } from "@/components/BreakerList";
import { QueueTable } from "@/components/QueueTable";
import { getOperations } from "@/lib/api/server";

/**
 * The operations screen (Step 17): the three delivery queues' current
 * workload and this tenant's own circuit breakers, for an operator asking
 * "is the delivery path healthy right now" — a different question from the
 * one the ledger (Step 14) answers about one message's own history.
 */
export default async function OperationsPage() {
  const operations = await getOperations();

  return (
    <div className="space-y-8">
      <h1 className="text-lg font-semibold text-text">Operations</h1>

      <section className="space-y-3">
        <h2 className="text-sm font-semibold text-text">Delivery queues</h2>
        <QueueTable queues={operations.queues} />
      </section>

      <section className="space-y-3">
        <h2 className="text-sm font-semibold text-text">Circuit breakers</h2>
        <BreakerList breakers={operations.breakers} />
      </section>
    </div>
  );
}
